<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Tests\Unit;

use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ImageInfo;
use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ResolvedDocument;
use Ggoitea\LaravelArgIdReader\Pipeline\DomicilioReadPipeline;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DomicilioReadPipelineTest extends TestCase
{
    private const TEXTO_CON_DOMICILIO = <<<'TXT'
    SECCION OTRO
    DOMICILIO
    CALLE FALSA 123, PISO 4
    LUGAR DE NACIMIENTO CIUDAD
    PROVINCIA
    TXT;

    private const TEXTO_SIN_DOMICILIO = <<<'TXT'
    DNI DOCUMENTO DE IDENTIDAD
    LUGAR DE NACIMIENTO CIUDAD
    TXT;

    private const FRENTE = 'frente.jpg';

    private const DORSO = 'dorso.jpg';

    public function test_extrae_el_domicilio_en_el_primer_intento(): void
    {
        $ocr = new RecordingOcr(self::TEXTO_CON_DOMICILIO);

        $report = $this->pipeline($ocr)->read($this->images());

        self::assertSame(ReadStatus::Completo, $report->status);
        self::assertSame('CALLE FALSA 123, PISO 4', $report->data?->domicilio);
        self::assertSame([], $report->missingFields);
        self::assertSame(1, $ocr->reads, 'el primer intento con la imagen original debe ser suficiente');
        self::assertSame([self::DORSO], $ocr->readPaths);
        self::assertSame('ok', $this->stage($report, 'domicilio.parse'));
    }

    public function test_sin_bloque_de_domicilio_reporta_sin_datos_y_agota_los_intentos(): void
    {
        $ocr = new RecordingOcr(self::TEXTO_SIN_DOMICILIO);
        $preprocessor = new TempCopyPreprocessor();

        $report = $this->pipeline($ocr, $preprocessor)->read($this->images());

        self::assertSame(ReadStatus::SinDatos, $report->status);
        self::assertNull($report->data);
        self::assertSame(['domicilio'], $report->missingFields);
        self::assertSame(3, $ocr->reads, 'debe agotar los 3 intentos de OCR (original + 2 mejoras)');
        self::assertSame(2, count($preprocessor->copies), 'solo los intentos 2 y 3 preprocesan');
    }

    public function test_error_al_resolver_el_documento_no_corre_ocr(): void
    {
        $ocr = new RecordingOcr(self::TEXTO_CON_DOMICILIO);

        $report = $this->pipeline($ocr, null, new FailingDocumentResolver())->read($this->images());

        self::assertSame(ReadStatus::Error, $report->status);
        self::assertNull($report->data);
        self::assertSame(0, $ocr->reads);
        self::assertSame('error', $this->stage($report, 'domicilio.document'));
    }

    public function test_limpia_las_imagenes_preprocesadas_incluso_con_error(): void
    {
        $ocr = new RecordingOcr(null);
        $preprocessor = new TempCopyPreprocessor();

        $this->pipeline($ocr, $preprocessor)->read($this->images());

        foreach ($preprocessor->copies as $copy) {
            self::assertFileDoesNotExist($copy->path);
            self::assertDirectoryDoesNotExist($copy->directory);
        }
    }

    /**
     * @param  mixed  $report
     */
    private function stage(mixed $report, string $name): ?string
    {
        foreach ($report->stages as $stage) {
            if ($stage->stage === $name) {
                return $stage->status->value;
            }
        }

        return null;
    }

    private function pipeline(OcrReader $ocr, ?TempCopyPreprocessor $preprocessor = null, ?DocumentResolver $documentos = null): DomicilioReadPipeline
    {
        return new DomicilioReadPipeline(
            documents: $documentos ?? new FakeDocumentResolver(),
            probe: new FakeImageProbe(),
            ocr: $ocr,
            preprocessor: $preprocessor ?? new TempCopyPreprocessor(),
            logger: new NullLogger,
        );
    }

    private function images(): DniImages
    {
        return new DniImages(frente: self::FRENTE, dorso: self::DORSO);
    }
}

final class FakeDocumentResolver extends DocumentResolver
{
    public function __construct() {}

    public function resolve(string $path, int $page = 1, int $dpi = 300): ResolvedDocument
    {
        return new ResolvedDocument(imagePath: $path, pages: 1);
    }

    public function esPdf(string $path): bool
    {
        return false;
    }

    public function paginas(string $path): int
    {
        return 1;
    }
}

final class FailingDocumentResolver extends DocumentResolver
{
    public function __construct() {}

    public function resolve(string $path, int $page = 1, int $dpi = 300): ResolvedDocument
    {
        throw new DocumentoInvalido('archivo_vacio');
    }
}

final class FakeImageProbe implements \Ggoitea\LaravelArgIdReader\Contracts\ImageProbe
{
    public function inspect(string $path): ImageInfo
    {
        return new ImageInfo(format: 'JPG', width: 1200, height: 800, bytes: 1000);
    }
}

final class RecordingOcr implements OcrReader
{
    public int $reads = 0;

    /** @var list<string> */
    public array $readPaths = [];

    public function __construct(private readonly ?string $text) {}

    public function read(string $imagePath, string $language = 'spa', string $pageSegmentation = '6', ?string $whitelist = null): ?string
    {
        $this->reads++;
        $this->readPaths[] = $imagePath;

        return $this->text;
    }
}

final class TempCopyPreprocessor implements ImagePreprocessor
{
    /** @var list<PreparedImage> */
    public array $copies = [];

    public function enhance(string $imagePath, bool $onlyBottom = false): PreparedImage
    {
        return $this->copy();
    }

    public function enhanceTop(string $imagePath, int $percent = 65): PreparedImage
    {
        return $this->copy();
    }

    public function enhanceAddress(string $imagePath, int $topPercent = 0): PreparedImage
    {
        return $this->copy();
    }

    public function rotate(string $imagePath, int $rotation): PreparedImage
    {
        return $this->copy();
    }

    private function copy(): PreparedImage
    {
        $directory = sys_get_temp_dir().'/argid-test-'.uniqid();
        @mkdir($directory, 0777, true);
        $path = $directory.'/copiado.jpg';
        @touch($path);

        return $this->copies[] = new PreparedImage($path, $directory);
    }
}
