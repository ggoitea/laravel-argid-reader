<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Tests\Unit;

use Ggoitea\LaravelArgIdReader\ArgIdReaderProvider;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Pipeline\DniReadPipeline;
use Ggoitea\LaravelArgIdReader\Pipeline\DomicilioReadPipeline;
use Orchestra\Testbench\TestCase;

final class ArgIdReaderProviderTest extends TestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ArgIdReaderProvider::class,
        ];
    }

    public function test_it_registers_the_read_pipeline(): void
    {
        $this->assertInstanceOf(
            DniReadPipeline::class,
            $this->app->make(ReadPipeline::class),
        );
    }

    public function test_it_registers_the_address_pipeline(): void
    {
        $reader = $this->app->make(DomicilioReadPipeline::class);

        $this->assertInstanceOf(DomicilioReadPipeline::class, $reader);
        $this->assertSame($reader, $this->app->make(DomicilioReadPipeline::class), 'debe ser singleton');
    }
}
