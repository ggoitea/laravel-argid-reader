<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

readonly class ResolvedDocument
{
    public function __construct(
        public string $imagePath,
        public bool $temporary = false,
        public ?string $tempDirectory = null,
        public ?int $pages = null,
    ) {}

    public function cleanup(): void
    {
        if ($this->temporary && $this->tempDirectory !== null) {
            foreach (glob($this->tempDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDirectory);
        }
    }
}
