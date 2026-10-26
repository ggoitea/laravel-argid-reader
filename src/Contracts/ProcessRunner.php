<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Contracts;

interface ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string,string>  $env
     */
    public function run(array $command, array $env = [], ?int $timeoutSeconds = null, ?int $idleTimeoutSeconds = null, ?int $maxOutputBytes = null): ProcessResult;
}
