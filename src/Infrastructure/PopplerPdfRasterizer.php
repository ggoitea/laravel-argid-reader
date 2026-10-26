<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Illuminate\Support\Str;

class PopplerPdfRasterizer
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function pageCount(string $pdfPath): int
    {
        $res = $this->runner->run(['pdfinfo', $pdfPath], [], 15);
        if (preg_match('/^Pages:\s+(\d+)/mi', $res->stdout, $m) !== 1) {
            throw new DocumentoInvalido('pdf_sin_paginas');
        }

        return (int) $m[1];
    }

    public function rasterizePage(string $pdfPath, int $page = 1, int $dpi = 300): RasterizedPage
    {
        $dir = sys_get_temp_dir().'/argid_'.Str::random(16);
        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new DocumentoInvalido('sin_tmp');
        }

        $prefix = $dir.'/page';
        $this->runner->run([
            'pdftoppm',
            '-f', (string) $page,
            '-l', (string) $page,
            '-r', (string) $dpi,
            '-png',
            '-singlefile',
            $pdfPath,
            $prefix,
        ], [], 60);

        $path = $prefix.'.png';
        if (! is_file($path)) {
            $this->limpiarDirectorio($dir);

            throw new DocumentoInvalido('pdf_no_rasterizado');
        }

        return new RasterizedPage($path, $dir);
    }

    public function limpiarDirectorio(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
