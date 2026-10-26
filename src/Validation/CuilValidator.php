<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Validation;

class CuilValidator
{
    /**
     * Deriva el CUIL a partir del campo "207" del esquema 2012: prefijo (20/23/24/27)
     * y dígito verificador. Devuelve null si la estructura o el dígito no son válidos.
     */
    public function deriveFrom2012(string $suffix, string $dni): ?string
    {
        $dni = preg_replace('/\D/', '', $dni) ?? '';
        if (strlen($dni) !== 8) {
            return null;
        }

        $suffix = preg_replace('/\D/', '', $suffix) ?? '';
        if (strlen($suffix) !== 3) {
            return null;
        }

        $prefix = substr($suffix, 0, 2);
        $checkDigit = substr($suffix, 2, 1);

        if (! in_array($prefix, ['20', '23', '24', '27'], true)) {
            return null;
        }

        $base = $prefix.$dni;
        if ($this->calculateCheckDigit($base) !== $checkDigit) {
            return null;
        }

        return $prefix.'-'.$dni.'-'.$checkDigit;
    }

    public function calculateCheckDigit(string $baseTenDigits): ?string
    {
        if (preg_match('/^\d{10}$/', $baseTenDigits) !== 1) {
            return null;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach (str_split($baseTenDigits) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        $result = 11 - ($sum % 11);
        if ($result === 11) {
            $result = 0;
        }
        if ($result === 10) {
            $result = 9;
        }

        return (string) $result;
    }
}
