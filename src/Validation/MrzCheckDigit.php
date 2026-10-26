<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Validation;

use InvalidArgumentException;

class MrzCheckDigit
{
    private const WEIGHTS = [7, 3, 1];

    public function compute(string $value): int
    {
        $sum = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $number = $this->valueOf($value[$index]);
            $sum += $number * self::WEIGHTS[$index % 3];
        }

        return $sum % 10;
    }

    public function isValid(string $value, string $checkDigit): bool
    {
        if (preg_match('/^\d$/', $checkDigit) !== 1) {
            return false;
        }

        return (string) $this->compute($value) === $checkDigit;
    }

    private function valueOf(string $character): int
    {
        if ($character === '<') {
            return 0;
        }

        if (ctype_digit($character)) {
            return (int) $character;
        }

        if ($character >= 'A' && $character <= 'Z') {
            return ord($character) - ord('A') + 10;
        }

        throw new InvalidArgumentException('caracter invalido en MRZ');
    }
}
