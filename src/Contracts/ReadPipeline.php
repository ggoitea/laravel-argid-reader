<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

use Ggoitea\LaravelArgIdReader\Domain\DniImages;
use Ggoitea\LaravelArgIdReader\Domain\ReadReport;

interface ReadPipeline
{
    public function read(DniImages $images): ReadReport;
}
