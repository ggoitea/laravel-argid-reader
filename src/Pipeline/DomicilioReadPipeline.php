<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Pipeline;

use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\ImageProbe;
use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;
use Ggoitea\LaravelArgIdReader\Domain\ReadReport;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Domain\StageReport;
use Ggoitea\LaravelArgIdReader\Domain\StageStatus;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ResolvedDocument;
use Ggoitea\LaravelArgIdReader\Parsing\DomicilioParser;
use Psr\Log\LoggerInterface;

/**
 * Lectura del domicilio impreso en el dorso mediante OCR. Etapa opcional e
 * independiente de la identificacion (barcode + MRZ): solo corre cuando el
 * caller la pide explicitamente. No registra datos personales.
 */
class DomicilioReadPipeline implements ReadPipeline
{
    private const LOG_CHANNEL = 'argid.domicilio_read';

    /**
     * enhance: null = imagen original, 0 = imagen completa mejorada,
     * >0 = recorte del porcentaje superior. El primer intento usa la imagen
     * original porque es el caso mas comun y evita el costo del preproceso.
     *
     * @var list<array{enhance:?int,language:string,segmentation:string}>
     */
    private const INTENTOS = [
        ['enhance' => null, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => 0, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => 65, 'language' => 'spa', 'segmentation' => '3'],
    ];

    public function __construct(
        private readonly DocumentResolver $documents,
        private readonly ImageProbe $probe,
        private readonly OcrReader $ocr,
        private readonly ImagePreprocessor $preprocessor,
        private readonly LoggerInterface $logger,
        private readonly DomicilioParser $parser = new DomicilioParser,
        private readonly int $minSide = 300,
        private readonly int $maxPixels = 40_000_000,
        private readonly int $pdfDpi = 300,
    ) {}

    public function read(DniImages $images): ReadReport
    {
        $startedAt = microtime(true);
        $stages = [];
        $resolved = null;
        $prepared = [];
        $domicilio = null;

        try {
            $resolved = $this->resolverEtapa($stages, $images);
            if ($resolved === null) {
                return $this->report(ReadStatus::Error, null, $stages, $startedAt);
            }

            if (! $this->inspeccionarEtapa($stages, $resolved->imagePath)) {
                return $this->report(ReadStatus::Error, null, $stages, $startedAt);
            }

            $domicilio = $this->intentarEtapas($stages, $resolved->imagePath, $prepared);
        } finally {
            foreach ($prepared as $image) {
                $image->cleanup();
            }
            $resolved?->cleanup();
        }

        if ($domicilio === null) {
            return $this->report(ReadStatus::SinDatos, null, $stages, $startedAt);
        }

        return $this->report(
            ReadStatus::Completo,
            (new DniData)->withDomicilio($domicilio),
            $stages,
            $startedAt,
        );
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function resolverEtapa(array &$stages, DniImages $images): ?ResolvedDocument
    {
        $startedAt = microtime(true);
        $page = $this->paginaDorso($images);

        try {
            $document = $this->documents->resolve($images->dorso, $page, $this->pdfDpi);
            $stages[] = new StageReport('domicilio.document', StageStatus::Ok, microtime(true) - $startedAt, [
                'pages' => $document->pages,
            ]);

            return $document;
        } catch (DocumentoInvalido $e) {
            $stages[] = new StageReport('domicilio.document', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => $e->codigo,
            ]);

            return null;
        } catch (\Throwable) {
            $stages[] = new StageReport('domicilio.document', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => 'documento_error',
            ]);

            return null;
        }
    }

    private function paginaDorso(DniImages $images): int
    {
        if ($images->frente !== $images->dorso) {
            return 1;
        }

        try {
            return $this->documents->esPdf($images->dorso) && $this->documents->paginas($images->dorso) >= 2 ? 2 : 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function inspeccionarEtapa(array &$stages, string $path): bool
    {
        $startedAt = microtime(true);

        try {
            $info = $this->probe->inspect($path);
        } catch (DocumentoInvalido $e) {
            $stages[] = new StageReport('domicilio.probe', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => $e->codigo,
            ]);

            return false;
        }

        if ($info->width < $this->minSide || $info->height < $this->minSide) {
            $stages[] = new StageReport('domicilio.probe', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => 'imagen_chica',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        if ($info->pixels() > $this->maxPixels) {
            $stages[] = new StageReport('domicilio.probe', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => 'imagen_demasiado_grande',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        $stages[] = new StageReport('domicilio.probe', StageStatus::Ok, microtime(true) - $startedAt, [
            'format' => $info->format,
            'width' => $info->width,
            'height' => $info->height,
        ]);

        return true;
    }

    /**
     * Hasta 3 intentos de OCR del dorso. Corta en el primer intento que
     * encuentre un domicilio plausible.
     *
     * @param  list<StageReport>  $stages
     * @param  list<PreparedImage>  $prepared
     */
    private function intentarEtapas(array &$stages, string $path, array &$prepared): ?string
    {
        foreach (self::INTENTOS as $index => $intento) {
            $startedAt = microtime(true);
            $source = $path;

            if ($intento['enhance'] !== null) {
                try {
                    $preparedImage = $this->preprocessor->enhanceAddress($path, $intento['enhance']);
                    $prepared[] = $preparedImage;
                    $source = $preparedImage->path;
                } catch (DocumentoInvalido $e) {
                    $stages[] = new StageReport('domicilio.preproceso', StageStatus::Error, microtime(true) - $startedAt, [
                        'code' => $e->codigo,
                        'attempt' => $index + 1,
                    ]);

                    continue;
                }
            }

            try {
                $texto = $this->ocr->read($source, $intento['language'], $intento['segmentation']);
            } catch (\Throwable) {
                $stages[] = new StageReport('domicilio.ocr', StageStatus::Error, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                    'language' => $intento['language'],
                ]);

                continue;
            }

            if ($texto === null) {
                $stages[] = new StageReport('domicilio.ocr', StageStatus::SinDatos, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                    'language' => $intento['language'],
                ]);

                continue;
            }

            $stages[] = new StageReport('domicilio.ocr', StageStatus::Ok, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
                'language' => $intento['language'],
            ]);

            try {
                $domicilio = $this->parser->parse($texto);
            } catch (\Throwable) {
                $domicilio = null;
            }

            if ($domicilio === null) {
                $stages[] = new StageReport('domicilio.parse', StageStatus::SinDatos, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                ]);

                continue;
            }

            $stages[] = new StageReport('domicilio.parse', StageStatus::Ok, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
            ]);

            return $domicilio;
        }

        return null;
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function report(ReadStatus $status, ?DniData $data, array $stages, float $startedAt): ReadReport
    {
        $report = new ReadReport(
            status: $status,
            data: $data,
            stages: $stages,
            missingFields: $data === null ? ['domicilio'] : [],
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->logger->info(self::LOG_CHANNEL, $report->toLogContext());

        return $report;
    }
}
