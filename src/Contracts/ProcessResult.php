<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
        public bool $timedOut = false,
    ) {}
}
