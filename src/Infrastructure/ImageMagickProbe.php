<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\ImageProbe;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;
use Ggoitea\LaravelArgIdReader\Domain\ImageInfo;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;

class ImageMagickProbe implements ImageProbe
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function inspect(string $path): ImageInfo
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DocumentoInvalido('archivo_inexistente');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new DocumentoInvalido('archivo_vacio');
        }

        $out = $this->identify(['magick', 'identify', '-format', '%m %w %h', $path]);
        if ($out === null) {
            $out = $this->identify(['identify', '-format', '%m %w %h', $path]);
        }

        if ($out === null) {
            throw new DocumentoInvalido('imagen_ilegible');
        }

        $parts = preg_split('/\s+/', trim($out)) ?: [];
        if (count($parts) < 3) {
            throw new DocumentoInvalido('imagen_ilegible');
        }

        return new ImageInfo(
            format: strtoupper($parts[0]),
            width: (int) $parts[1],
            height: (int) $parts[2],
            bytes: $size,
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function identify(array $command): ?string
    {
        try {
            $res = $this->runner->run($command, [
                'MAGICK_THREAD_LIMIT' => '1',
                'MAGICK_MEMORY_LIMIT' => '256MiB',
                'MAGICK_MAP_LIMIT' => '512MiB',
                'MAGICK_DISK_LIMIT' => '1GiB',
            ], 15, 10, 8192);
        } catch (\Throwable) {
            return null;
        }

        $out = trim($res->stdout);

        return $out === '' ? null : $out;
    }
}
