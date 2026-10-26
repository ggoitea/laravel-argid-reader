<?php

declare(strict_types=1);

namespace Ggoitea\LaravelArgIdReader\Infrastructure;

use Ggoitea\LaravelArgIdReader\Contracts\ProcessResult;
use Ggoitea\LaravelArgIdReader\Contracts\ProcessRunner;
use Ggoitea\LaravelArgIdReader\Exceptions\ProcessOutputLimit;
use Ggoitea\LaravelArgIdReader\Exceptions\ProcessTimeout;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, array $env = [], ?int $timeoutSeconds = null, ?int $idleTimeoutSeconds = null, ?int $maxOutputBytes = null): ProcessResult
    {
        $timeoutSeconds = $timeoutSeconds ?? 30;
        $idleTimeoutSeconds = $idleTimeoutSeconds ?? 10;
        $maxOutputBytes = $maxOutputBytes ?? 1024 * 1024;

        $cwd = getcwd();
        if (function_exists('base_path')) {
            try {
                $bp = base_path();
                if (is_string($bp) && $bp !== '') {
                    $cwd = $bp;
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        $process = new Process($command, $cwd, $env, null, (float) $timeoutSeconds);
        $process->setIdleTimeout((float) $idleTimeoutSeconds);

        $start = microtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ProcessTimeout('process timeout');
        }

        $duration = microtime(true) - $start;

        $out = $process->getOutput();
        $err = $process->getErrorOutput();

        if (strlen($out) + strlen($err) > $maxOutputBytes) {
            $process->stop();

            throw new ProcessOutputLimit('process output limit exceeded');
        }

        if (! $process->isSuccessful() && $process->getExitCode() === null) {
            throw new ProcessTimeout('process timeout');
        }

        return new ProcessResult(
            exitCode: (int) $process->getExitCode(),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationSeconds: $duration,
            timedOut: false,
        );
    }
}
