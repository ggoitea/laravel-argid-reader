<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

interface OcrReader
{
    public function read(string $imagePath, string $language = 'spa', string $pageSegmentation = '6', ?string $whitelist = null): ?string;
}
