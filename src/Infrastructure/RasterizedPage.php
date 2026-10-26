<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

readonly class RasterizedPage
{
    public function __construct(
        public string $path,
        public string $directory,
    ) {}
}
