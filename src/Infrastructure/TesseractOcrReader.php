<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;

class TesseractOcrReader implements OcrReader
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function read(string $imagePath, string $language = 'spa', string $pageSegmentation = '6', ?string $whitelist = null): ?string
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            return null;
        }

        $command = [
            'tesseract',
            $imagePath,
            'stdout',
            '--psm',
            $pageSegmentation,
            '-l',
            $language,
        ];

        if ($whitelist !== null && $whitelist !== '') {
            $command[] = '-c';
            $command[] = 'tessedit_char_whitelist='.$whitelist;
        }

        try {
            $result = $this->runner->run($command, [], 45, 20, 256 * 1024);
        } catch (\Throwable) {
            return null;
        }

        if ($result->exitCode !== 0) {
            return null;
        }

        $text = trim($result->stdout);

        return $text === '' ? null : $text;
    }
}
