<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class ReadReport
{
    /**
     * @param  list<StageReport>  $stages
     * @param  list<string>  $missingFields
     * @param  array<string,string>  $conflicts
     */
    public function __construct(
        public ReadStatus $status,
        public ?DniData $data = null,
        public array $stages = [],
        public array $missingFields = [],
        public array $conflicts = [],
        public float $durationSeconds = 0.0,
    ) {}

    /**
     * Contexto seguro para logs: sin datos personales ni rutas de archivos.
     *
     * @return array<string,mixed>
     */
    public function toLogContext(): array
    {
        return [
            'status' => $this->status->value,
            'duration_ms' => (int) round($this->durationSeconds * 1000),
            'missing_fields' => $this->missingFields,
            'conflicts' => array_keys($this->conflicts),
            'stages' => array_map(fn (StageReport $stage) => $stage->toLogContext(), $this->stages),
        ];
    }
}
