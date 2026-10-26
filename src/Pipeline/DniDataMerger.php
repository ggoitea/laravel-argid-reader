<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Pipeline;

use Ggoitea\LaravelArgIdReader\Domain\DniData;
use Ggoitea\LaravelArgIdReader\Domain\MergeResult;
use Carbon\CarbonImmutable;

/**
 * Combina datos del codigo de barras (fuente base) con la MRZ (fuente de
 * respaldo) sin resolver diferencias en silencio.
 */
class DniDataMerger
{
    private const CAMPOS_CONFLICTIVOS = ['dni', 'tramite', 'sexo', 'fechaNacimiento'];

    public function merge(?DniData $base, ?DniData $extra): MergeResult
    {
        if ($base === null) {
            return new MergeResult($extra);
        }

        if ($extra === null) {
            return new MergeResult($base);
        }

        $conflicts = $this->detectarConflictos($base, $extra);

        $raw = $base->raw;
        if ($extra->raw !== []) {
            $raw['mrz'] = $extra->raw;
        }

        return new MergeResult(
            new DniData(
                dni: $base->dni ?? $extra->dni,
                tramite: $base->tramite ?? $extra->tramite,
                apellido: $base->apellido ?? $extra->apellido,
                nombre: $base->nombre ?? $extra->nombre,
                sexo: $base->sexo ?? $extra->sexo,
                fechaNacimiento: $base->fechaNacimiento ?? $extra->fechaNacimiento,
                emision: $base->emision ?? $extra->emision,
                expiracion: $base->expiracion ?? $extra->expiracion,
                ejemplar: $base->ejemplar ?? $extra->ejemplar,
                nacionalidad: $base->nacionalidad ?? $extra->nacionalidad,
                cuil: $base->cuil ?? $extra->cuil,
                tipoTarjeta: $base->tipoTarjeta ?? $extra->tipoTarjeta,
                domicilio: $base->domicilio ?? $extra->domicilio,
                raw: $raw,
            ),
            $conflicts,
        );
    }

    /**
     * @return array<string,string>
     */
    private function detectarConflictos(DniData $base, DniData $extra): array
    {
        $conflicts = [];

        foreach (self::CAMPOS_CONFLICTIVOS as $field) {
            $a = $this->comparable($base->{$field});
            $b = $this->comparable($extra->{$field});

            if ($a !== null && $b !== null && $a !== $b) {
                $conflicts[$field] = 'conflicto_entre_fuentes';
            }
        }

        return $conflicts;
    }

    private function comparable(string|CarbonImmutable|null $value): ?string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
