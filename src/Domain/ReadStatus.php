<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

enum ReadStatus: string
{
    case Completo = 'completo';
    case Parcial = 'parcial';
    case Conflicto = 'conflicto';
    case SinCodigo = 'sin_codigo';
    case SinDatos = 'sin_datos';
    case NoSoportado = 'no_soportado';
    case Error = 'error';
}
