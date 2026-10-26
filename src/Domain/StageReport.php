<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class StageReport
{
    public function __construct(
        public string $stage,
        public StageStatus $status,
        public float $durationSeconds,
        public array $meta = [],
    ) {}

    /**
     * Contexto seguro para logs: sin payloads, nombres, DNI ni rutas.
     *
     * @return array{stage:string,status:string,duration_ms:int,meta:array<string,mixed>}
     */
    public function toLogContext(): array
    {
        $meta = array_intersect_key($this->meta, array_flip([
            'face',
            'format',
            'code',
            'pages',
            'width',
            'height',
            'attempt',
            'language',
            'rotation',
            'repaired_fields',
        ]));

        return [
            'stage' => $this->stage,
            'status' => $this->status->value,
            'duration_ms' => (int) round($this->durationSeconds * 1000),
            'meta' => $meta,
        ];
    }
}
