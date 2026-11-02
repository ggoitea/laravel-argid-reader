<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Tests\Unit;

use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ImageInfo;
use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ResolvedDocument;
use Ggoitea\LaravelArgIdReader\Pipeline\MrzReadPipeline;
use Ggoitea\LaravelArgIdReader\Parsing\MrzTd1Parser;
use Ggoitea\LaravelArgIdReader\Validation\MrzCheckDigit;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MrzReadPipelineTest extends TestCase
{
    public function test_extrae_identidad_de_la_mrz_solo_con_ocr_de_mrz(): void
    {
        $ocr = new MrzRecordingOcr($this->mrzValida());
        $preprocessor = new CountingPreprocessor();
        $pipeline = new MrzReadPipeline(
            documents: new FakeMrzDocumentResolver(),
            probe: new FakeMrzImageProbe(),
            ocr: $ocr,
            preprocessor: $preprocessor,
            parser: new MrzTd1Parser(),
            logger: new NullLogger,
        );

        $report = $pipeline->read($this->images());

        // La MRZ aporta dni y sexo pero nunca tramite (solo lo da el barcode),
        // asi que su reporte propio es Parcial.
        self::assertSame(ReadStatus::Parcial, $report->status);
        self::assertSame(['tramite'], $report->missingFields);
        self::assertSame('12345678', $report->data?->dni);
        self::assertSame('GONZALEZ', $report->data?->apellido);
        self::assertSame('JUAN', $report->data?->nombre);
        self::assertNull($report->data?->domicilio);
        self::assertSame(1, $ocr->reads, 'al validar la MRZ no debe correr OCR de domicilio');
        self::assertSame(['dorso.jpg'], $ocr->readPaths);
        self::assertStringContainsString('0123456789AB', $ocr->lastWhitelist ?? '', 'el OCR debe usar whitelist de MRZ');
        self::assertSame([], $preprocessor->called, 'el primer intento debe bastar: sin preprocesos');
    }

    private function images(): DniImages
    {
        return new DniImages(frente: 'frente.jpg', dorso: 'dorso.jpg');
    }

    private function mrzValida(): string
    {
        $checkDigit = new MrzCheckDigit;

        $documento = '12345678<';
        $checkDocumento = $checkDigit->compute($documento);
        $linea1 = 'IDARG'.$documento.$checkDocumento.str_repeat('<', 15);

        $nacimiento = '900101';
        $checkNacimiento = $checkDigit->compute($nacimiento);
        $expiracion = '300101';
        $checkExpiracion = $checkDigit->compute($expiracion);

        $composite = substr($linea1, 5, 9).$linea1[14].$nacimiento.$checkNacimiento.$expiracion.$checkExpiracion;
        $checkComposite = $checkDigit->compute($composite);

        $linea2 = $nacimiento.$checkNacimiento.'M'.$expiracion.$checkExpiracion.'ARG'.str_repeat('<', 11).$checkComposite;

        $linea3 = str_pad('GONZALEZ<<JUAN', 30, '<');

        return implode("\n", [$linea1, $linea2, $linea3]);
    }
}

final class MrzRecordingOcr implements OcrReader
{
    public int $reads = 0;

    public ?string $lastWhitelist = null;

    /** @var list<string> */
    public array $readPaths = [];

    public function __construct(private readonly string $text) {}

    public function read(string $imagePath, string $language = 'spa', string $pageSegmentation = '6', ?string $whitelist = null): ?string
    {
        $this->reads++;
        $this->readPaths[] = $imagePath;
        $this->lastWhitelist = $whitelist;

        return $this->text;
    }
}

final class FakeMrzDocumentResolver extends DocumentResolver
{
    public function __construct() {}

    public function resolve(string $path, int $page = 1, int $dpi = 300): ResolvedDocument
    {
        return new ResolvedDocument(imagePath: $path, pages: 1);
    }
}

final class FakeMrzImageProbe implements \Ggoitea\LaravelArgIdReader\Contracts\ImageProbe
{
    public function inspect(string $path): ImageInfo
    {
        return new ImageInfo(format: 'JPG', width: 1200, height: 800, bytes: 1000);
    }
}

final class CountingPreprocessor implements ImagePreprocessor
{
    /** @var list<string> */
    public array $called = [];

    public function enhance(string $imagePath, bool $onlyBottom = false): PreparedImage
    {
        $this->called[] = 'enhance';

        return $this->prepared();
    }

    public function enhanceTop(string $imagePath, int $percent = 65): PreparedImage
    {
        $this->called[] = 'enhanceTop';

        return $this->prepared();
    }

    public function enhanceAddress(string $imagePath, int $topPercent = 0): PreparedImage
    {
        $this->called[] = 'enhanceAddress';

        return $this->prepared();
    }

    public function rotate(string $imagePath, int $rotation): PreparedImage
    {
        $this->called[] = 'rotate:'.$rotation;

        return $this->prepared();
    }

    private function prepared(): PreparedImage
    {
        $directory = sys_get_temp_dir().'/argid-test-'.uniqid();
        @mkdir($directory, 0777, true);
        $path = $directory.'/copiado.jpg';
        @touch($path);

        return new PreparedImage($path, $directory);
    }
}
