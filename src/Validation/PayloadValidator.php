<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Validation;

use Carbon\CarbonImmutable;

class PayloadValidator
{
    public function isValidDni(?string $dni): bool
    {
        if ($dni === null) {
            return false;
        }
        $dni = preg_replace('/\D/', '', $dni);

        return preg_match('/^\d{8}$/', $dni) === 1;
    }

    public function isValidTramite(?string $tramite): bool
    {
        if ($tramite === null) {
            return false;
        }

        return preg_match('/^\d{8,12}$/', $tramite) === 1;
    }

    public function isValidSexo(?string $sexo): bool
    {
        return in_array($sexo, ['M', 'F'], true);
    }

    public function parseDate(string $date, string $format = 'd/m/Y'): ?CarbonImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if ($format === 'd/m/y') {
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{2})$/', $date, $m) !== 1) {
                return null;
            }

            $day = (int) $m[1];
            $month = (int) $m[2];
            $currentYear = CarbonImmutable::now()->year;
            $year = intdiv($currentYear, 100) * 100 + (int) $m[3];
            if ($year > $currentYear) {
                $year -= 100;
            }

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m) !== 1) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
    }
}
