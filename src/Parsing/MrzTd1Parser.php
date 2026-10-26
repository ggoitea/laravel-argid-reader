<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Parsing;

use Ggoitea\LaravelArgIdReader\Domain\MrzData;
use Ggoitea\LaravelArgIdReader\Validation\MrzCheckDigit;
use Carbon\CarbonImmutable;

class MrzTd1Parser
{
    private const LINE_LENGTH = 30;

    private const MAX_REMOVALS = 2;

    private const NUMERIC_CONFUSIONS = [
        'O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0',
        'I' => '1', 'L' => '1', 'T' => '7',
        'S' => '5', 'B' => '8', 'Z' => '2', 'G' => '6', 'A' => '4',
    ];

    private const LETTER_CONFUSIONS = [
        '0' => 'O', '1' => 'I', '2' => 'Z', '4' => 'A',
        '5' => 'S', '6' => 'G', '7' => 'T', '8' => 'B',
    ];

    public function __construct(
        private readonly MrzCheckDigit $checkDigit = new MrzCheckDigit,
    ) {}

    public function parse(string $text): ?MrzData
    {
        $lines = $this->extractLines($text);
        if ($lines === null) {
            return null;
        }

        [$line1, $line2, $line3] = $lines;

        $reparados = [];

        $documento = $this->repairDocumentNumber(substr($line1, 5, 9), $line1[14], $reparados);
        if ($documento === null) {
            return null;
        }

        $segundaLinea = $this->parseSecondLine($line1, $line2, $reparados);
        if ($segundaLinea === null) {
            return null;
        }

        $dni = str_replace('<', '', $documento);
        if (preg_match('/^\d{8}$/', $dni) !== 1) {
            return null;
        }

        [$apellido, $nombre] = $this->parseNames($line3);

        $fechaNacimiento = $this->parseBirthDate($segundaLinea['nacimiento']);
        $fechaExpiracion = $this->parseExpiryDate($segundaLinea['expiracion']);
        if ($fechaNacimiento === null || $fechaExpiracion === null) {
            return null;
        }

        return new MrzData(
            dni: $dni,
            apellido: $apellido,
            nombre: $nombre,
            sexo: $segundaLinea['sexo'],
            fechaNacimiento: $fechaNacimiento,
            expiracion: $fechaExpiracion,
            nacionalidad: $segundaLinea['nacionalidad'],
            camposReparados: array_values(array_unique($reparados)),
        );
    }

    /**
     * @return array{0:string,1:string,2:string}|null
     */
    private function extractLines(string $text): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $candidates = [];

        foreach (explode("\n", $text) as $rawLine) {
            $line = strtoupper($rawLine);
            $line = preg_replace('/\s+/', '', $line) ?? '';
            $line = preg_replace('/[^A-Z0-9<]/', '', $line) ?? '';

            if (strlen($line) >= 15) {
                $candidates[] = $line;
            }
        }

        foreach ($candidates as $index => $candidate) {
            if (! str_starts_with($candidate, 'IDARG')) {
                continue;
            }

            $line1 = $this->normalizeFirstLine($candidate);
            if ($line1 === null) {
                continue;
            }

            return [$line1, $candidates[$index + 1] ?? '', $candidates[$index + 2] ?? ''];
        }

        return null;
    }

    private function normalizeFirstLine(string $candidate): ?string
    {
        foreach ($this->reduceTo30($candidate) as $line) {
            if (str_starts_with($line, 'IDARG') && $this->checkDigit->isValid(substr($line, 5, 9), $line[14])) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $reparados
     * @return array{nacimiento:string,sexo:string,expiracion:string,nacionalidad:string}|null
     */
    private function parseSecondLine(string $line1, string $line2, array &$reparados): ?array
    {
        foreach ($this->reduceTo30($line2) as $candidate) {
            $local = [];

            $segundaLinea = $this->validateSecondLine($line1, $candidate, $local);
            if ($segundaLinea === null) {
                continue;
            }

            if ($candidate !== $line2) {
                $local[] = 'linea2';
            }

            $reparados = array_merge($reparados, $local);

            return $segundaLinea;
        }

        return null;
    }

    /**
     * @param  list<string>  $reparados
     * @return array{nacimiento:string,sexo:string,expiracion:string,nacionalidad:string}|null
     */
    private function validateSecondLine(string $line1, string $line2, array &$reparados): ?array
    {
        $nacimiento = $this->repairNumericDate(substr($line2, 0, 6), $line2[6], 'fecha_nacimiento', $reparados);
        if ($nacimiento === null) {
            return null;
        }

        $sexo = $line2[7];
        if ($sexo !== 'M' && $sexo !== 'F') {
            return null;
        }

        $expiracion = $this->repairNumericDate(substr($line2, 8, 6), $line2[14], 'expiracion', $reparados);
        if ($expiracion === null) {
            return null;
        }

        $nacionalidad = $this->repairNationality(substr($line2, 15, 3));
        if ($nacionalidad === null) {
            return null;
        }

        $composite = substr($line1, 5, 9).$line1[14].$nacimiento.$line2[6].$expiracion.$line2[14];
        if (! $this->checkDigit->isValid($composite, $line2[29])) {
            return null;
        }

        return [
            'nacimiento' => $nacimiento,
            'sexo' => $sexo,
            'expiracion' => $expiracion,
            'nacionalidad' => $nacionalidad,
        ];
    }

    /**
     * Genera variantes de 30 caracteres eliminando como maximo dos caracteres.
     *
     * @return list<string>
     */
    private function reduceTo30(string $line): array
    {
        if (strlen($line) === self::LINE_LENGTH) {
            return [$line];
        }

        if (strlen($line) < self::LINE_LENGTH) {
            return [];
        }

        $results = [];
        $queue = [[$line, 0]];

        while ($queue !== []) {
            [$current, $removals] = array_shift($queue);
            $length = strlen($current);

            if ($length === self::LINE_LENGTH) {
                $results[$current] = true;

                continue;
            }

            if ($removals >= self::MAX_REMOVALS) {
                continue;
            }

            for ($index = 0; $index < $length; $index++) {
                $queue[] = [substr($current, 0, $index).substr($current, $index + 1), $removals + 1];
            }
        }

        return array_keys($results);
    }

    /**
     * @param  list<string>  $reparados
     */
    private function repairDocumentNumber(string $field, string $checkDigit, array &$reparados): ?string
    {
        if (preg_match('/^[0-9<]{9}$/', $field) === 1 && $this->checkDigit->isValid($field, $checkDigit)) {
            return $field;
        }

        $reparado = strtr($field, self::NUMERIC_CONFUSIONS);
        if (preg_match('/^[0-9<]{9}$/', $reparado) !== 1) {
            return null;
        }

        if (! $this->checkDigit->isValid($reparado, $checkDigit)) {
            return null;
        }

        $reparados[] = 'dni';

        return $reparado;
    }

    /**
     * @param  list<string>  $reparados
     */
    private function repairNumericDate(string $field, string $checkDigit, string $name, array &$reparados): ?string
    {
        if (preg_match('/^\d{6}$/', $field) === 1 && $this->checkDigit->isValid($field, $checkDigit)) {
            return $field;
        }

        $reparado = strtr($field, self::NUMERIC_CONFUSIONS);
        if (preg_match('/^\d{6}$/', $reparado) !== 1) {
            return null;
        }

        if (! $this->checkDigit->isValid($reparado, $checkDigit)) {
            return null;
        }

        $reparados[] = $name;

        return $reparado;
    }

    private function repairNationality(string $nationality): ?string
    {
        $nationality = strtr($nationality, self::LETTER_CONFUSIONS);

        return $nationality === 'ARG' ? 'ARG' : null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseNames(string $line3): array
    {
        $line3 = substr($line3, 0, self::LINE_LENGTH);
        $line3 = str_pad($line3, self::LINE_LENGTH, '<');
        $line3 = strtr($line3, self::LETTER_CONFUSIONS);
        $line3 = preg_replace('/[^A-Z<]/', '', $line3) ?? '';
        $line3 = rtrim($line3, '<');

        $parts = explode('<<', $line3, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        $apellido = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $parts[0])) ?? '');
        $nombre = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $parts[1])) ?? '');

        return [$apellido, $nombre];
    }

    private function parseBirthDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return $this->pickSmallestFutureOrLatestPast((int) $m[1], (int) $m[2], (int) $m[3], preferFuture: false);
    }

    private function parseExpiryDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return $this->pickSmallestFutureOrLatestPast((int) $m[1], (int) $m[2], (int) $m[3], preferFuture: true);
    }

    private function pickSmallestFutureOrLatestPast(int $yearShort, int $month, int $day, bool $preferFuture): ?CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $candidates = [];

        foreach ([1900, 2000, 2100] as $century) {
            $year = $century + $yearShort;
            if (checkdate($month, $day, $year)) {
                $candidates[] = CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
            }
        }

        if ($candidates === []) {
            return null;
        }

        $future = array_values(array_filter($candidates, fn (CarbonImmutable $date): bool => $date->greaterThanOrEqualTo($today)));
        $past = array_values(array_filter($candidates, fn (CarbonImmutable $date): bool => $date->lessThan($today)));

        if ($preferFuture) {
            if ($future !== []) {
                usort($future, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->timestamp <=> $b->timestamp);

                return $future[0];
            }

            usort($past, fn (CarbonImmutable $a, CarbonImmutable $b): int => $b->timestamp <=> $a->timestamp);

            return $past[0] ?? null;
        }

        if ($past !== []) {
            usort($past, fn (CarbonImmutable $a, CarbonImmutable $b): int => $b->timestamp <=> $a->timestamp);

            return $past[0];
        }

        usort($future, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->timestamp <=> $b->timestamp);

        return $future[0] ?? null;
    }
}
