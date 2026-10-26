<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Pipeline;

use Ggoitea\LaravelArgIdReader\Contracts\BarcodeReader;
use Ggoitea\LaravelArgIdReader\Contracts\ImageProbe;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ReadReport;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Domain\StageReport;
use Ggoitea\LaravelArgIdReader\Domain\StageStatus;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Ggoitea\LaravelArgIdReader\Exceptions\UnsupportedPayload;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ResolvedDocument;
use Ggoitea\LaravelArgIdReader\Parsing\BarcodePayloadParser;
use Psr\Log\LoggerInterface;

/**
 * Etapa 1: validación de documentos + lectura de códigos (PDF417/QR) + parseo.
 * No ejecuta OCR ni MRZ. No registra datos personales.
 */
class BarcodeReadPipeline implements ReadPipeline
{
    private const CAMPOS_CONFLICTIVOS = ['dni', 'tramite', 'sexo'];

    public function __construct(
        private readonly DocumentResolver $documents,
        private readonly ImageProbe $probe,
        private readonly BarcodeReader $barcodeReader,
        private readonly BarcodePayloadParser $parser,
        private readonly LoggerInterface $logger,
        private readonly int $minSide = 300,
        private readonly int $maxPixels = 40_000_000,
        private readonly int $pdfDpi = 300,
    ) {}

    public function read(DniImages $images): ReadReport
    {
        $startedAt = microtime(true);
        $stages = [];
        $resolved = [];
        $data = null;
        $conflicts = [];
        $anyUnsupported = false;
        $anyError = false;

        try {
            foreach (['frente' => $images->frente, 'dorso' => $images->dorso] as $face => $path) {
                if ($data?->hasRequiredFields()) {
                    $stages[] = new StageReport("barcode.{$face}", StageStatus::Omitido, 0.0, ['face' => $face]);

                    continue;
                }

                $documento = $this->resolverEtapa($stages, $face, $path, $this->paginaPara($face, $images));
                if ($documento === null) {
                    $anyError = true;

                    continue;
                }
                $resolved[] = $documento;

                if (! $this->inspeccionarEtapa($stages, $face, $documento->imagePath)) {
                    $anyError = true;

                    continue;
                }

                $payload = $this->barcodeEtapa($stages, $face, $documento->imagePath);
                if ($payload === null) {
                    continue;
                }

                try {
                    $parsed = $this->parser->parse($payload);
                } catch (UnsupportedPayload) {
                    $stages[] = new StageReport("parse.{$face}", StageStatus::NoSoportado, 0.0, ['face' => $face]);
                    $anyUnsupported = true;

                    continue;
                }

                if ($parsed === null) {
                    $stages[] = new StageReport("parse.{$face}", StageStatus::SinDatos, 0.0, ['face' => $face]);

                    continue;
                }

                $stages[] = new StageReport("parse.{$face}", StageStatus::Ok, 0.0, [
                    'face' => $face,
                    'format' => $parsed->tipoTarjeta,
                ]);

                if ($data === null) {
                    $data = $parsed;

                    continue;
                }

                $conflicts = $this->detectarConflictos($data, $parsed);
                if ($conflicts !== []) {
                    break;
                }
            }
        } finally {
            foreach ($resolved as $document) {
                $document->cleanup();
            }
        }

        $report = new ReadReport(
            status: $this->resolverEstado($data, $conflicts, $anyUnsupported, $anyError),
            data: $data,
            stages: $stages,
            missingFields: $data?->missingRequiredFields() ?? ['dni', 'tramite', 'sexo'],
            conflicts: $conflicts,
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->logger->info('argid.barcode_read', $report->toLogContext());

        return $report;
    }

    private function paginaPara(string $face, DniImages $images): int
    {
        if ($face !== 'dorso' || $images->frente !== $images->dorso) {
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
    private function resolverEtapa(array &$stages, string $face, string $path, int $page): ?ResolvedDocument
    {
        $startedAt = microtime(true);

        try {
            $document = $this->documents->resolve($path, $page, $this->pdfDpi);
            $stages[] = new StageReport("document.{$face}", StageStatus::Ok, microtime(true) - $startedAt, [
                'face' => $face,
                'pages' => $document->pages,
            ]);

            return $document;
        } catch (DocumentoInvalido $e) {
            $stages[] = new StageReport("document.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => $e->codigo,
            ]);

            return null;
        } catch (\Throwable) {
            $stages[] = new StageReport("document.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => 'documento_error',
            ]);

            return null;
        }
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function inspeccionarEtapa(array &$stages, string $face, string $path): bool
    {
        $startedAt = microtime(true);

        try {
            $info = $this->probe->inspect($path);
        } catch (DocumentoInvalido $e) {
            $stages[] = new StageReport("probe.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => $e->codigo,
            ]);

            return false;
        }

        if ($info->width < $this->minSide || $info->height < $this->minSide) {
            $stages[] = new StageReport("probe.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => 'imagen_chica',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        if ($info->pixels() > $this->maxPixels) {
            $stages[] = new StageReport("probe.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => 'imagen_demasiado_grande',
                'width' => $info->width,
                'height' => $info->height,
            ]);

            return false;
        }

        $stages[] = new StageReport("probe.{$face}", StageStatus::Ok, microtime(true) - $startedAt, [
            'face' => $face,
            'format' => $info->format,
            'width' => $info->width,
            'height' => $info->height,
        ]);

        return true;
    }

    /**
     * @param  list<StageReport>  $stages
     */
    private function barcodeEtapa(array &$stages, string $face, string $path): ?string
    {
        $startedAt = microtime(true);

        try {
            $payload = $this->barcodeReader->read($path);
        } catch (\Throwable) {
            $stages[] = new StageReport("barcode.{$face}", StageStatus::Error, microtime(true) - $startedAt, [
                'face' => $face,
                'code' => 'barcode_error',
            ]);

            return null;
        }

        if ($payload === null) {
            $stages[] = new StageReport("barcode.{$face}", StageStatus::SinCodigo, microtime(true) - $startedAt, [
                'face' => $face,
            ]);

            return null;
        }

        $stages[] = new StageReport("barcode.{$face}", StageStatus::Ok, microtime(true) - $startedAt, [
            'face' => $face,
        ]);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function detectarConflictos(DniData $actual, DniData $nuevo): array
    {
        $conflicts = [];

        foreach (self::CAMPOS_CONFLICTIVOS as $field) {
            $a = $actual->{$field};
            $b = $nuevo->{$field};

            if ($a !== null && $b !== null && $a !== $b) {
                $conflicts[$field] = 'conflicto_entre_codigos';
            }
        }

        return $conflicts;
    }

    private function resolverEstado(?DniData $data, array $conflicts, bool $anyUnsupported, bool $anyError): ReadStatus
    {
        if ($conflicts !== []) {
            return ReadStatus::Conflicto;
        }

        if ($data === null) {
            if ($anyError) {
                return ReadStatus::Error;
            }

            return $anyUnsupported ? ReadStatus::NoSoportado : ReadStatus::SinCodigo;
        }

        return $data->hasRequiredFields() ? ReadStatus::Completo : ReadStatus::Parcial;
    }
}
