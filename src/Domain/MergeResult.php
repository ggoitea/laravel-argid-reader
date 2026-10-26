<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class MergeResult
{
    /**
     * @param  array<string,string>  $conflicts
     */
    public function __construct(
        public ?DniData $data,
        public array $conflicts = [],
    ) {}
}
