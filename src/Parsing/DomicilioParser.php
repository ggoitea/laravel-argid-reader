<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Parsing;

/**
 * Recorta el domicilio del texto OCR del dorso: comienza en "DOMICILIO" y
 * termina en "LUGAR DE NACIMIENTO" o, como maximo, tres lineas despues.
 */
class DomicilioParser
{
    /**
     * El OCR del dorso suele leer el acento de "DOMÍCILIO" o confundir la I
     * con un 1.
     */
    private const INICIO_PATTERN = '/D[OÓ0]M[IÍ1]C[IÍ1]L[IÍ1][OÓ0]/iu';

    private const FINES = ['LUGAR DE NACIMIENTO', 'LUGAR DE NAC'];

    private const MAX_LINEAS = 3;

    public function parse(string $text): ?string
    {
        if (preg_match(self::INICIO_PATTERN, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$marker, $offset] = $match[0];

        $resto = substr($text, $offset + strlen($marker));

        foreach (self::FINES as $fin) {
            $position = stripos($resto, $fin);
            if ($position !== false) {
                $resto = substr($resto, 0, $position);

                break;
            }
        }

        $lineas = [];
        foreach (preg_split('/\R/u', $resto) ?: [] as $rawLine) {
            $line = $this->limpiar($rawLine);
            if ($line === '') {
                continue;
            }

            if (mb_stripos($line, 'LUGAR') !== false) {
                break;
            }

            $lineas[] = $line;

            if (count($lineas) === self::MAX_LINEAS) {
                break;
            }
        }

        $domicilio = trim(implode(' ', $lineas));

        return $this->esPlausible($domicilio) ? $domicilio : null;
    }

    private function limpiar(string $line): string
    {
        $line = preg_replace('/[^\p{L}\p{N}\s.,\-\/#°ºª\'&]/u', ' ', $line) ?? '';
        $line = preg_replace('/\s+/u', ' ', $line) ?? '';

        return trim($line, " \t\n\r\0\x0B.,-");
    }

    private function esPlausible(string $value): bool
    {
        if (mb_strlen($value) < 4) {
            return false;
        }

        if (preg_match('/\p{L}/u', $value) !== 1) {
            return false;
        }

        $upper = mb_strtoupper($value);

        return preg_match(self::INICIO_PATTERN, $upper) !== 1
            && ! str_contains($upper, 'LUGAR DE NAC');
    }
}
