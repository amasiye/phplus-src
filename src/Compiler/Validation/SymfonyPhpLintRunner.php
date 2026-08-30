<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Validation;

use Amasiye\Ppphp\Compiler\Validation\Interfaces\PhpLintRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class SymfonyPhpLintRunner implements PhpLintRunner
{
    public function run(string $path, float $timeoutSeconds): PhpLintResult
    {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return new PhpLintResult(
                $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput(),
                true,
                $exception->getMessage(),
            );
        } catch (\Throwable $exception) {
            return new PhpLintResult(
                $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput(),
                false,
                $exception::class . ': ' . $exception->getMessage(),
            );
        }

        return new PhpLintResult(
            $process->getExitCode(),
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
