<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Validation;

use Atatusoft\Ppphp\Compiler\Validation\Interfaces\PhpLintRunner;
use Atatusoft\Ppphp\Process\BoundedProcessRunner;

final readonly class SymfonyPhpLintRunner implements PhpLintRunner
{
    public function __construct(private BoundedProcessRunner $processes = new BoundedProcessRunner()) {}

    public function run(string $path, float $timeoutSeconds): PhpLintResult
    {
        $result = $this->processes->run(
            [PHP_BINARY, '-n', '-l', $path],
            null,
            $timeoutSeconds,
            65_536,
            65_536,
        );

        return new PhpLintResult(
            $result->exitCode,
            $result->stdout,
            $result->stderr,
            $result->timedOut,
            $result->outputLimitExceeded
                ? 'PHP lint output exceeded its safety limit.'
                : $result->executionFailure,
        );
    }
}
