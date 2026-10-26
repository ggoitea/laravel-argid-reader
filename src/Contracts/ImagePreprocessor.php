<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;

interface ImagePreprocessor
{
    /**
     * Genera una copia temporal mejorada para OCR. El llamador debe limpiarla.
     */
    public function enhance(string $imagePath, bool $onlyBottom = false): PreparedImage;

    /**
     * Genera una copia temporal mejorada del tercio superior del dorso.
     * El llamador debe limpiarla.
     */
    public function enhanceTop(string $imagePath, int $percent = 65): PreparedImage;

    /**
     * Genera una copia temporal para OCR de texto impreso (domicilio). No
     * aplica sharpen porque degrada el reconocimiento de las etiquetas.
     * topPercent 0 significa imagen completa. El llamador debe limpiarla.
     */
    public function enhanceAddress(string $imagePath, int $topPercent = 0): PreparedImage;

    /**
     * Genera una copia temporal rotada sin otras mejoras. El llamador debe limpiarla.
     */
    public function rotate(string $imagePath, int $rotation): PreparedImage;
}
