<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

use Atatusoft\Ppphp\Process\BoundedProcessRunner;

class PhpStanProcessRunner
{
    public function __construct(private readonly BoundedProcessRunner $processes = new BoundedProcessRunner()) {}

    /** @param list<string> $command */
    public function run(array $command, string $workingDirectory, float $timeout): PhpStanProcessResult
    {
        $result = $this->processes->run($command, $workingDirectory, $timeout);

        return new PhpStanProcessResult(
            $command,
            $result->stdout,
            $result->stderr,
            $result->exitCode ?? -1,
            $result->timedOut,
            $result->outputLimitExceeded,
            $result->executionFailure,
        );
    }
}
