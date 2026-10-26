<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

use Carbon\CarbonImmutable;

readonly class DniData
{
    public function __construct(
        public ?string $dni = null,
        public ?string $tramite = null,
        public ?string $apellido = null,
        public ?string $nombre = null,
        public ?string $sexo = null,
        public ?CarbonImmutable $fechaNacimiento = null,
        public ?CarbonImmutable $emision = null,
        public ?CarbonImmutable $expiracion = null,
        public ?string $ejemplar = null,
        public ?string $nacionalidad = null,
        public ?string $cuil = null,
        public ?string $tipoTarjeta = null,
        public ?string $domicilio = null,
        public array $raw = [],
    ) {}

    /**
     * Campos exigidos para validar contra RENAPER.
     *
     * @return list<string>
     */
    public function missingRequiredFields(): array
    {
        $missing = [];
        if ($this->dni === null) {
            $missing[] = 'dni';
        }
        if ($this->tramite === null) {
            $missing[] = 'tramite';
        }
        if ($this->sexo === null) {
            $missing[] = 'sexo';
        }

        return $missing;
    }

    public function hasRequiredFields(): bool
    {
        return $this->missingRequiredFields() === [];
    }

    public function hasAnyRequiredField(): bool
    {
        return $this->dni !== null || $this->tramite !== null || $this->sexo !== null;
    }

    public function withDomicilio(?string $domicilio): self
    {
        if ($this->domicilio === $domicilio) {
            return $this;
        }

        return new self(
            dni: $this->dni,
            tramite: $this->tramite,
            apellido: $this->apellido,
            nombre: $this->nombre,
            sexo: $this->sexo,
            fechaNacimiento: $this->fechaNacimiento,
            emision: $this->emision,
            expiracion: $this->expiracion,
            ejemplar: $this->ejemplar,
            nacionalidad: $this->nacionalidad,
            cuil: $this->cuil,
            tipoTarjeta: $this->tipoTarjeta,
            domicilio: $domicilio,
            raw: $this->raw,
        );
    }

    public function toArray(): array
    {
        return [
            'dni' => $this->dni,
            'tramite' => $this->tramite,
            'apellido' => $this->apellido,
            'nombre' => $this->nombre,
            'sexo' => $this->sexo,
            'fecha_nacimiento' => $this->fechaNacimiento?->format('Y-m-d'),
            'emision' => $this->emision?->format('Y-m-d'),
            'expiracion' => $this->expiracion?->format('Y-m-d'),
            'ejemplar' => $this->ejemplar,
            'nacionalidad' => $this->nacionalidad,
            'cuil' => $this->cuil,
            'tipo_tarjeta' => $this->tipoTarjeta,
            'domicilio' => $this->domicilio,
        ];
    }
}
