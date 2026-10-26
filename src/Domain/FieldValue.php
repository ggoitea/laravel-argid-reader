<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class FieldValue
{
    public function __construct(
        public ?string $value,
        public bool $valid = true,
        public ?string $reason = null,
    ) {}
}
