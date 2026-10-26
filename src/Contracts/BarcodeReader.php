<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

interface BarcodeReader
{
    /**
     * Devuelve el payload del primer PDF417/QR detectado, o null si no hay código legible.
     */
    public function read(string $imagePath): ?string;
}
