<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class ImageInfo
{
    public function __construct(
        public string $format,
        public int $width,
        public int $height,
        public int $bytes,
    ) {}

    public function pixels(): int
    {
        return $this->width * $this->height;
    }
}
