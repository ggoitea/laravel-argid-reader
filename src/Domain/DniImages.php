<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

readonly class DniImages
{
    public array $meta;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public string $frente,
        public string $dorso,
        array $meta = [],
    ) {
        $defaults = [
            'frente_mime' => null,
            'frente_width' => null,
            'frente_height' => null,
            'dorso_mime' => null,
            'dorso_width' => null,
            'dorso_height' => null,
            'frente_pages' => null,
            'dorso_pages' => null,
        ];
        $this->meta = array_merge($defaults, $meta);
    }

    public function withMeta(array $meta): self
    {
        return new self($this->frente, $this->dorso, array_merge($this->meta, $meta));
    }
}
