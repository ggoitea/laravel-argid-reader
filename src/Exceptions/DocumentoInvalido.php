<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Exceptions;

use RuntimeException;

class DocumentoInvalido extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $codigo);
    }
}
