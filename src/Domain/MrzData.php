<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

use Carbon\CarbonImmutable;

readonly class MrzData
{
    /**
     * @param  list<string>  $camposReparados
     */
    public function __construct(
        public string $dni,
        public string $apellido,
        public string $nombre,
        public string $sexo,
        public CarbonImmutable $fechaNacimiento,
        public CarbonImmutable $expiracion,
        public string $nacionalidad,
        public array $camposReparados = [],
    ) {}

    public function toDniData(): DniData
    {
        return new DniData(
            dni: $this->dni,
            apellido: $this->apellido !== '' ? $this->apellido : null,
            nombre: $this->nombre !== '' ? $this->nombre : null,
            sexo: $this->sexo,
            fechaNacimiento: $this->fechaNacimiento,
            expiracion: $this->expiracion,
            nacionalidad: $this->nacionalidad,
            tipoTarjeta: 'mrz',
            raw: ['scheme' => 'mrz', 'repaired' => $this->camposReparados],
        );
    }
}
