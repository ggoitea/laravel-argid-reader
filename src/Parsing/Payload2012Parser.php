<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Parsing;

use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Exceptions\UnsupportedPayload;
use Ggoitea\LaravelArgIdReader\Validation\CuilValidator;
use Ggoitea\LaravelArgIdReader\Validation\PayloadValidator;

class Payload2012Parser
{
    public function __construct(
        private readonly PayloadValidator $validator = new PayloadValidator,
        private readonly CuilValidator $cuil = new CuilValidator,
    ) {}

    public function parse(string $payload): DniData
    {
        $parts = explode('@', $payload);
        if (count($parts) !== 9) {
            throw new UnsupportedPayload('2012 schema expects 9 parts');
        }

        $tramite = trim($parts[0]);
        $apellido = trim($parts[1]);
        $nombre = trim($parts[2]);
        $sexo = trim($parts[3]);
        $dni = trim($parts[4]);
        $ejemplar = trim($parts[5]);
        $fechaNacimiento = $this->validator->parseDate(trim($parts[6]), 'd/m/Y');
        $emision = $this->validator->parseDate(trim($parts[7]), 'd/m/Y');
        $suffix = trim($parts[8]);
        $cuil = $this->cuil->deriveFrom2012($suffix, $dni);

        if (! $this->validator->isValidDni($dni)) {
            throw new UnsupportedPayload('invalid dni');
        }
        if (! $this->validator->isValidTramite($tramite)) {
            throw new UnsupportedPayload('invalid tramite');
        }
        if (! $this->validator->isValidSexo($sexo)) {
            throw new UnsupportedPayload('invalid sexo');
        }
        if ($fechaNacimiento === null || $emision === null) {
            throw new UnsupportedPayload('invalid dates');
        }

        return new DniData(
            dni: preg_replace('/\D/', '', $dni),
            tramite: $tramite,
            apellido: $apellido,
            nombre: $nombre,
            sexo: $sexo,
            fechaNacimiento: $fechaNacimiento,
            emision: $emision,
            expiracion: null,
            ejemplar: $ejemplar,
            nacionalidad: null,
            cuil: $cuil,
            tipoTarjeta: '2012',
            raw: ['scheme' => '2012', 'parts' => count($parts)],
        );
    }
}
