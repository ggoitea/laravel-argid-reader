<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Tests\Unit;

use Ggoitea\LaravelArgIdReader\ArgIdReaderProvider;
use Ggoitea\LaravelArgIdReader\Contracts\ReadPipeline;
use Ggoitea\LaravelArgIdReader\Pipeline\DniReadPipeline;
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
}
