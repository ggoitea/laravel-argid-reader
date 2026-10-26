<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

use Ggoitea\LaravelArgIdReader\Domain\ImageInfo;

interface ImageProbe
{
    public function inspect(string $path): ImageInfo;
}
