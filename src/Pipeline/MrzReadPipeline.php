<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Pipeline;

use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\ImageProbe;
use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\MrzData;
use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;
use Ggoitea\LaravelArgIdReader\Domain\ReadReport;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Domain\StageReport;
use Ggoitea\LaravelArgIdReader\Domain\StageStatus;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ResolvedDocument;
use Ggoitea\LaravelArgIdReader\Parsing\DomicilioParser;
use Ggoitea\LaravelArgIdReader\Parsing\MrzTd1Parser;
use Psr\Log\LoggerInterface;

/**
 * Etapa 2: lectura de la MRZ TD1 del dorso con OCR y validación por dígitos
 * de control. No registra el texto OCR ni datos personales.
 */
class MrzReadPipeline implements ReadPipeline
{
    private const WHITELIST_MRZ = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ<';

    /** @var list<array{enhance:?string,rotation:int,language:string,segmentation:string}> */
    private const INTENTOS = [
        ['enhance' => null, 'rotation' => 0, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => null, 'rotation' => 180, 'language' => 'eng', 'segmentation' => '3'],
        ['enhance' => null, 'rotation' => 90, 'language' => 'eng', 'segmentation' => '3'],
        ['enhance' => null, 'rotation' => 270, 'language' => 'eng', 'segmentation' => '3'],
        ['enhance' => 'full', 'rotation' => 0, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => 'bottom', 'rotation' => 0, 'language' => 'eng', 'segmentation' => '3'],
    ];

    /**
     * enhance: null = imagen original, 0 = mejora completa, >0 = recorte superior.
     *
     * @var list<array{enhance:?int,language:string,segmentation:string}>
     */
    private const INTENTOS_DOMICILIO = [
        ['enhance' => null, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => 0, 'language' => 'spa', 'segmentation' => '3'],
        ['enhance' => 65, 'language' => 'spa', 'segmentation' => '3'],
    ];

    public function __construct(
        private readonly DocumentResolver $documents,
        private readonly ImageProbe $probe,
        private readonly OcrReader $ocr,
        private readonly ImagePreprocessor $preprocessor,
        private readonly MrzTd1Parser $parser,
        private readonly LoggerInterface $logger,
        private readonly DomicilioParser $domicilioParser = new DomicilioParser,
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
        $mrz = null;
        $domicilio = null;

        try {
            $resolved = $this->resolverEtapa($stages, $images);
            if ($resolved === null) {
                return $this->report(ReadStatus::Error, null, $stages, $startedAt);
            }

            if (! $this->inspeccionarEtapa($stages, $resolved->imagePath)) {
                return $this->report(ReadStatus::Error, null, $stages, $startedAt);
            }

            foreach (self::INTENTOS as $index => $intento) {
                $source = $resolved->imagePath;

                if ($intento['rotation'] !== 0 || $intento['enhance'] !== null) {
                    try {
                        $preparedImage = $intento['rotation'] !== 0
                            ? $this->preprocessor->rotate($resolved->imagePath, $intento['rotation'])
                            : $this->preprocessor->enhance($resolved->imagePath, $intento['enhance'] === 'bottom');
                        $prepared[] = $preparedImage;
                        $source = $preparedImage->path;
                    } catch (DocumentoInvalido $e) {
                        $stages[] = new StageReport('preproceso.mrz', StageStatus::Error, 0.0, [
                            'code' => $e->codigo,
                            'attempt' => $index + 1,
                            'rotation' => $intento['rotation'],
                        ]);

                        continue;
                    }
                }

                $texto = $this->ocrEtapa($stages, $source, $intento, $index);
                if ($texto === null) {
                    continue;
                }

                $mrz = $this->parseEtapa($stages, $texto, $index);
                if ($mrz !== null) {
                    break;
                }
            }

            $domicilio = $this->domicilioEtapa($stages, $resolved->imagePath, $prepared);
        } finally {
            foreach ($prepared as $image) {
                $image->cleanup();
            }
            $resolved?->cleanup();
        }

        if ($mrz === null && $domicilio === null) {
            return $this->report(ReadStatus::SinDatos, null, $stages, $startedAt);
        }

        $data = ($mrz?->toDniData() ?? new DniData)->withDomicilio($domicilio);

        return $this->report(
            $mrz === null
                ? ReadStatus::SinDatos
                : ($data->hasRequiredFields() ? ReadStatus::Completo : ReadStatus::Parcial),
            $data,
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
            $stages[] = new StageReport('document.dorso', StageStatus::Ok, microtime(true) - $startedAt, [
                'pages' => $document->pages,
            ]);

            return $document;
        } catch (DocumentoInvalido $e) {
            $stages[] = new StageReport('document.dorso', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => $e->codigo,
            ]);

            return null;
        } catch (\Throwable) {
            $stages[] = new StageReport('document.dorso', StageStatus::Error, microtime(true) - $startedAt, [
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
            $stages[] = new StageReport('probe.dorso', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => $e->codigo,
            ]);

            return false;
        }

        if ($info->width < $this->minSide || $info->height < $this->minSide) {
            $stages[] = new StageReport('probe.dorso', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => 'imagen_chica',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        if ($info->pixels() > $this->maxPixels) {
            $stages[] = new StageReport('probe.dorso', StageStatus::Error, microtime(true) - $startedAt, [
                'code' => 'imagen_demasiado_grande',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        $stages[] = new StageReport('probe.dorso', StageStatus::Ok, microtime(true) - $startedAt, [
            'format' => $info->format,
            'width' => $info->width,
            'height' => $info->height,
        ]);

        return true;
    }

    /**
     * @param  list<StageReport>  $stages
     * @param  array{enhance:?string,rotation:int,language:string,segmentation:string}  $intento
     */
    private function ocrEtapa(array &$stages, string $path, array $intento, int $index): ?string
    {
        $startedAt = microtime(true);

        try {
            $texto = $this->ocr->read($path, $intento['language'], $intento['segmentation'], self::WHITELIST_MRZ);
        } catch (\Throwable) {
            $stages[] = new StageReport('ocr.mrz', StageStatus::Error, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
                'language' => $intento['language'],
                'rotation' => $intento['rotation'],
            ]);

            return null;
        }

        if ($texto === null) {
            $stages[] = new StageReport('ocr.mrz', StageStatus::SinDatos, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
                'language' => $intento['language'],
                'rotation' => $intento['rotation'],
            ]);

            return null;
        }

        $stages[] = new StageReport('ocr.mrz', StageStatus::Ok, microtime(true) - $startedAt, [
            'attempt' => $index + 1,
            'language' => $intento['language'],
            'rotation' => $intento['rotation'],
        ]);

        return $texto;
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function parseEtapa(array &$stages, string $texto, int $index): ?MrzData
    {
        $startedAt = microtime(true);

        try {
            $mrz = $this->parser->parse($texto);
        } catch (\Throwable) {
            $stages[] = new StageReport('parse.mrz', StageStatus::Error, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
            ]);

            return null;
        }

        if ($mrz === null) {
            $stages[] = new StageReport('parse.mrz', StageStatus::SinDatos, microtime(true) - $startedAt, [
                'attempt' => $index + 1,
            ]);

            return null;
        }

        $stages[] = new StageReport('parse.mrz', StageStatus::Ok, microtime(true) - $startedAt, [
            'attempt' => $index + 1,
            'format' => 'td1',
            'repaired_fields' => $mrz->camposReparados,
        ]);

        return $mrz;
    }

    /**
     * @param  list<StageReport>  $stages
     * @param  list<PreparedImage>  $prepared
     */
    private function domicilioEtapa(array &$stages, string $path, array &$prepared): ?string
    {
        foreach (self::INTENTOS_DOMICILIO as $index => $intento) {
            $startedAt = microtime(true);
            $source = $path;

            if ($intento['enhance'] !== null) {
                try {
                    $preparedImage = $this->preprocessor->enhanceAddress($path, $intento['enhance']);
                    $prepared[] = $preparedImage;
                    $source = $preparedImage->path;
                } catch (DocumentoInvalido $e) {
                    $stages[] = new StageReport('preproceso.domicilio', StageStatus::Error, microtime(true) - $startedAt, [
                        'code' => $e->codigo,
                        'attempt' => $index + 1,
                    ]);

                    continue;
                }
            }

            try {
                $texto = $this->ocr->read($source, $intento['language'], $intento['segmentation']);
            } catch (\Throwable) {
                $stages[] = new StageReport('ocr.domicilio', StageStatus::Error, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                    'language' => $intento['language'],
                ]);

                continue;
            }

            if ($texto === null) {
                $stages[] = new StageReport('ocr.domicilio', StageStatus::SinDatos, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                    'language' => $intento['language'],
                ]);

                continue;
            }

            try {
                $domicilio = $this->domicilioParser->parse($texto);
            } catch (\Throwable) {
                $domicilio = null;
            }

            if ($domicilio === null) {
                $stages[] = new StageReport('parse.domicilio', StageStatus::SinDatos, microtime(true) - $startedAt, [
                    'attempt' => $index + 1,
                ]);

                continue;
            }

            $stages[] = new StageReport('parse.domicilio', StageStatus::Ok, microtime(true) - $startedAt, [
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
            missingFields: $data?->missingRequiredFields() ?? ['dni', 'tramite', 'sexo'],
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->logger->info('argid.mrz_read', $report->toLogContext());

        return $report;
    }
}
