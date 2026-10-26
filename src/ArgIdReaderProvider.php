<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader;

use Ggoitea\LaravelArgIdReader\Contracts\BarcodeReader;
use Ggoitea\LaravelArgIdReader\Contracts\ImagePreprocessor;
use Ggoitea\LaravelArgIdReader\Contracts\ImageProbe;
use Ggoitea\LaravelArgIdReader\Contracts\OcrReader;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Infrastructure\DocumentResolver;
use Ggoitea\LaravelArgIdReader\Infrastructure\ImageMagickPreprocessor;
use Ggoitea\LaravelArgIdReader\Infrastructure\ImageMagickProbe;
use Ggoitea\LaravelArgIdReader\Infrastructure\SymfonyProcessRunner;
use Ggoitea\LaravelArgIdReader\Infrastructure\TesseractOcrReader;
use Ggoitea\LaravelArgIdReader\Infrastructure\ZxingBarcodeReader;
use Ggoitea\LaravelArgIdReader\Parsing\BarcodePayloadParser;
use Ggoitea\LaravelArgIdReader\Parsing\DomicilioParser;
use Ggoitea\LaravelArgIdReader\Parsing\MrzTd1Parser;
use Ggoitea\LaravelArgIdReader\Pipeline\BarcodeReadPipeline;
use Ggoitea\LaravelArgIdReader\Pipeline\DniDataMerger;
use Ggoitea\LaravelArgIdReader\Pipeline\DniReadPipeline;
use Ggoitea\LaravelArgIdReader\Pipeline\MrzReadPipeline;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class ArgIdReaderProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BarcodeReader::class, ZxingBarcodeReader::class);
        $this->app->bind(ImagePreprocessor::class, ImageMagickPreprocessor::class);
        $this->app->bind(ImageProbe::class, ImageMagickProbe::class);
        $this->app->bind(OcrReader::class, TesseractOcrReader::class);

        $this->app->singleton(DniReadPipeline::class, function ($app) {
            $logger = $app->make(LoggerInterface::class);

            $barcodePipeline = new BarcodeReadPipeline(
                documents: $app->make(DocumentResolver::class),
                probe: $app->make(ImageProbe::class),
                barcodeReader: $app->make(BarcodeReader::class),
                parser: new BarcodePayloadParser(),
                logger: $logger,
                minSide: 300,
                maxPixels: 40_000_000,
                pdfDpi: 300,
            );

            $mrzPipeline = new MrzReadPipeline(
                documents: $app->make(DocumentResolver::class),
                probe: $app->make(ImageProbe::class),
                ocr: $app->make(OcrReader::class),
                preprocessor: $app->make(ImagePreprocessor::class),
                parser: new MrzTd1Parser(),
                logger: $logger,
                domicilioParser: new DomicilioParser(),
                minSide: 300,
                maxPixels: 40_000_000,
                pdfDpi: 300,
            );

            return new DniReadPipeline(
                barcode: $barcodePipeline,
                mrz: $mrzPipeline,
                merger: new DniDataMerger(),
                logger: $logger,
            );
        });

        $this->app->bind(
            ProcessRunner::class,
            SymfonyProcessRunner::class
        );

        $this->app->bind(ReadPipeline::class, DniReadPipeline::class);
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
