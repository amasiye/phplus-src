<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PhpStanProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, string $workingDirectory, float $timeout): PhpStanProcessResult
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);
        $timedOut = false;

        try {
            $exitCode = $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $exitCode = -1;
        }

        return new PhpStanProcessResult(
            $command,
            $process->getOutput(),
            $process->getErrorOutput(),
            $exitCode,
            $timedOut,
        );
    }
}
