<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;
use Ggoitea\LaravelArgIdReader\Domain\PreparedImage;
use Ggoitea\LaravelArgIdReader\Exceptions\DocumentoInvalido;
use Illuminate\Support\Str;

class ImageMagickPreprocessor implements ImagePreprocessor
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {}

    public function enhance(string $imagePath, bool $onlyBottom = false): PreparedImage
    {
        $operations = ['-colorspace', 'Gray', '-normalize', '-sharpen', '0x1'];

        if ($onlyBottom) {
            $operations = array_merge($operations, ['-gravity', 'South', '-crop', '100%x40%+0+0', '+repage']);
        }

        $operations = array_merge($operations, ['-resize', $onlyBottom ? '250%' : '200%', '-depth', '8']);

        return $this->transform($imagePath, $operations);
    }

    public function rotate(string $imagePath, int $rotation): PreparedImage
    {
        return $this->transform($imagePath, ['-rotate', (string) $rotation]);
    }

    public function enhanceTop(string $imagePath, int $percent = 65): PreparedImage
    {
        return $this->transform($imagePath, [
            '-gravity', 'North',
            '-crop', "100%x{$percent}%+0+0",
            '+repage',
            '-colorspace', 'Gray',
            '-normalize',
            '-sharpen', '0x1',
            '-resize', '200%',
            '-depth', '8',
        ]);
    }

    public function enhanceAddress(string $imagePath, int $topPercent = 0): PreparedImage
    {
        $operations = [];

        if ($topPercent > 0) {
            $operations = ['-gravity', 'North', '-crop', "100%x{$topPercent}%+0+0", '+repage'];
        }

        return $this->transform($imagePath, array_merge($operations, [
            '-colorspace', 'Gray',
            '-normalize',
            '-resize', '200%',
            '-depth', '8',
        ]));
    }

    /**
     * @param  list<string>  $operations
     */
    private function transform(string $imagePath, array $operations): PreparedImage
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new DocumentoInvalido('archivo_inexistente');
        }

        $directory = sys_get_temp_dir().'/argid_mrz_'.Str::random(16);
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new DocumentoInvalido('sin_tmp');
        }

        $output = $directory.'/mrz.png';

        $command = array_merge(['magick', $imagePath, '-auto-orient'], $operations, [$output]);

        try {
            $result = $this->runner->run($command, [
                'MAGICK_THREAD_LIMIT' => '1',
                'MAGICK_MEMORY_LIMIT' => '256MiB',
                'MAGICK_MAP_LIMIT' => '512MiB',
                'MAGICK_DISK_LIMIT' => '1GiB',
            ], 45, 20, 8192);
        } catch (\Throwable) {
            $this->cleanup($directory, $output);

            throw new DocumentoInvalido('preproceso_error');
        }

        if ($result->exitCode !== 0 || ! is_file($output)) {
            $this->cleanup($directory, $output);

            throw new DocumentoInvalido('preproceso_error');
        }

        return new PreparedImage($output, $directory);
    }

    private function cleanup(string $directory, string $output): void
    {
        @unlink($output);
        @rmdir($directory);
    }
}
