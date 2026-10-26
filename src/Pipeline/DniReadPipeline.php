<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Pipeline;

use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ReadReport;
use Ggoitea\LaravelArgIdReader\Domain\ReadStatus;
use Ggoitea\LaravelArgIdReader\Domain\StageReport;
use Psr\Log\LoggerInterface;

/**
 * Orquestador: intenta primero los codigos de barras y solo recurre a la MRZ
 * cuando faltan campos obligatorios. No repite etapas exitosas ni resuelve
 * conflictos en silencio. No registra datos personales.
 */
class DniReadPipeline implements ReadPipeline
{
    private const CAMPOS_OBLIGATORIOS = ['dni', 'tramite', 'sexo'];

    public function __construct(
        private readonly ReadPipeline $barcode,
        private readonly ReadPipeline $mrz,
        private readonly DniDataMerger $merger,
        private readonly LoggerInterface $logger,
    ) {}

    public function read(DniImages $images): ReadReport
    {
        $startedAt = microtime(true);

        $barcode = $this->barcode->read($images);
        $stages = $barcode->stages;

        if ($barcode->status === ReadStatus::Completo || $barcode->status === ReadStatus::Conflicto) {
            return $this->report(
                $barcode->status,
                $barcode->data,
                $stages,
                $barcode->missingFields,
                $barcode->conflicts,
                $startedAt,
            );
        }

        $mrz = $this->mrz->read($images);
        $stages = array_merge($stages, $this->prefijarEtapas($mrz->stages));

        $merge = $this->merger->merge($barcode->data, $mrz->data);

        return $this->report(
            $this->resolverEstado($merge->data, $merge->conflicts, $barcode, $mrz),
            $merge->data,
            $stages,
            $merge->data?->missingRequiredFields() ?? self::CAMPOS_OBLIGATORIOS,
            $merge->conflicts,
            $startedAt,
        );
    }

    /**
     * @param  list<StageReport>  $stages
     * @return list<StageReport>
     */
    private function prefijarEtapas(array $stages): array
    {
        return array_map(
            fn (StageReport $stage): StageReport => new StageReport(
                'mrz.'.$stage->stage,
                $stage->status,
                $stage->durationSeconds,
                $stage->meta,
            ),
            $stages,
        );
    }

    /**
     * @param  array<string,string>  $conflicts
     */
    private function resolverEstado(?DniData $data, array $conflicts, ReadReport $barcode, ReadReport $mrz): ReadStatus
    {
        if ($conflicts !== []) {
            return ReadStatus::Conflicto;
        }

        if ($data !== null) {
            if ($data->hasRequiredFields()) {
                return ReadStatus::Completo;
            }

            if ($data->hasAnyRequiredField()) {
                return ReadStatus::Parcial;
            }
        }

        if ($barcode->status === ReadStatus::Error || $mrz->status === ReadStatus::Error) {
            return ReadStatus::Error;
        }

        if ($barcode->status === ReadStatus::NoSoportado) {
            return ReadStatus::NoSoportado;
        }

        if ($mrz->status === ReadStatus::SinDatos || $barcode->status === ReadStatus::SinDatos) {
            return ReadStatus::SinDatos;
        }

        return ReadStatus::SinCodigo;
    }

    /**
     * @param  list<StageReport>  $stages
     * @param  list<string>  $missingFields
     * @param  array<string,string>  $conflicts
     */
    private function report(
        ReadStatus $status,
        ?DniData $data,
        array $stages,
        array $missingFields,
        array $conflicts,
        float $startedAt,
    ): ReadReport {
        $report = new ReadReport(
            status: $status,
            data: $data,
            stages: $stages,
            missingFields: $missingFields,
            conflicts: $conflicts,
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->logger->info('argid.read', $report->toLogContext());

        return $report;
    }
}
