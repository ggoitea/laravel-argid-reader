<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\BarcodeReader;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;

class ZxingBarcodeReader implements BarcodeReader
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function read(string $imagePath): ?string
    {
        if (! is_file($imagePath)) {
            return null;
        }

        try {
            $res = $this->runner->run([
                'ZXingReader',
                '-1',
                '-single',
                '-formats',
                'PDF417,QRCode',
                $imagePath,
            ], [], 20, 10, 64 * 1024);
        } catch (\Throwable) {
            return null;
        }

        return $this->extraerPayload($res->stdout, $imagePath);
    }

    private function extraerPayload(string $stdout, string $imagePath): ?string
    {
        $line = trim(str_replace("\r", '', $stdout));
        if ($line === '') {
            return null;
        }

        $prefix = $imagePath.' ';
        if (str_starts_with($line, $prefix)) {
            $line = substr($line, strlen($prefix));
        }

        if ($line === 'None') {
            return null;
        }

        if (preg_match('/"(?:[^"\\\\]|\\\\.)*"/s', $line) !== 1) {
            return null;
        }

        $start = strpos($line, '"');
        $raw = substr($line, $start + 1, -1);
        $payload = stripcslashes($raw);

        return $payload === '' ? null : $payload;
    }
}
