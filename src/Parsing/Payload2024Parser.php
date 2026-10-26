<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Parsing;

use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Exceptions\UnsupportedPayload;
use Ggoitea\LaravelArgIdReader\Validation\PayloadValidator;

class Payload2024Parser
{
    public function __construct(
        private readonly PayloadValidator $validator = new PayloadValidator,
    ) {}

    public function parse(string $payload): DniData
    {
        $parts = explode('@', $payload);
        if (count($parts) !== 8) {
            throw new UnsupportedPayload('2024 schema expects 8 parts');
        }

        $tramite = trim($parts[0]);
        $apellido = trim($parts[1]);
        $nombre = trim($parts[2]);
        $dni = trim($parts[3]);
        $ejemplar = trim($parts[4]);
        $fechaNacimiento = $this->validator->parseDate(trim($parts[5]), 'd/m/y');
        $emision = $this->validator->parseDate(trim($parts[6]), 'd/m/y');
        $jwt = trim($parts[7]);

        if (! $this->validator->isValidDni($dni)) {
            throw new UnsupportedPayload('invalid dni');
        }
        if (! $this->validator->isValidTramite($tramite)) {
            throw new UnsupportedPayload('invalid tramite');
        }
        if ($fechaNacimiento === null || $emision === null) {
            throw new UnsupportedPayload('invalid dates');
        }
        if ($jwt === '') {
            throw new UnsupportedPayload('invalid jwt');
        }

        return new DniData(
            dni: preg_replace('/\D/', '', $dni),
            tramite: $tramite,
            apellido: $apellido,
            nombre: $nombre,
            sexo: null,
            fechaNacimiento: $fechaNacimiento,
            emision: $emision,
            expiracion: null,
            ejemplar: $ejemplar,
            nacionalidad: null,
            cuil: null,
            tipoTarjeta: '2024',
            raw: ['scheme' => '2024', 'parts' => count($parts), 'firma_jwt' => $jwt],
        );
    }
}
