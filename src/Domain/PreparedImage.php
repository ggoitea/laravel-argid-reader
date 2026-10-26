<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class PreparedImage
{
    public function __construct(
        public string $path,
        public string $directory,
    ) {}

    public function cleanup(): void
    {
        @unlink($this->path);
        @rmdir($this->directory);
    }
}
