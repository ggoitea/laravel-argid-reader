<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Domain;

enum StageStatus: string
{
    case Ok = 'ok';
    case SinCodigo = 'sin_codigo';
    case SinDatos = 'sin_datos';
    case NoSoportado = 'no_soportado';
    case Error = 'error';
    case Omitido = 'omitido';
}
