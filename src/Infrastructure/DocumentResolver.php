<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;

class DocumentResolver
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    private const EXTENSIONES_IMAGEN = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly PopplerPdfRasterizer $pdfs,
    ) {}

    public function esPdf(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
            return true;
        }

        $mime = @mime_content_type($path);

        return $mime === 'application/pdf';
    }

    public function paginas(string $path): int
    {
        if (! $this->esPdf($path)) {
            return 1;
        }

        return $this->pdfs->pageCount($path);
    }

    public function resolve(string $path, int $page = 1, int $dpi = 300): ResolvedDocument
    {
        $this->validarArchivo($path);

        if ($this->esPdf($path)) {
            $pages = $this->pdfs->pageCount($path);
            if ($page < 1 || $page > $pages) {
                throw new DocumentoInvalido('pdf_pagina_inexistente');
            }
            $rasterized = $this->pdfs->rasterizePage($path, $page, $dpi);

            return new ResolvedDocument(
                imagePath: $rasterized->path,
                temporary: true,
                tempDirectory: $rasterized->directory,
                pages: $pages,
            );
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONES_IMAGEN, true)) {
            throw new DocumentoInvalido('formato_no_soportado');
        }

        return new ResolvedDocument(imagePath: $path, pages: 1);
    }

    private function validarArchivo(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DocumentoInvalido('archivo_inexistente');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new DocumentoInvalido('archivo_vacio');
        }
        if ($size > self::MAX_BYTES) {
            throw new DocumentoInvalido('archivo_demasiado_grande');
        }
    }
}
