<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class BoundedProcessRunner
{
    public function __construct(private readonly ProcessPolicy $policy = new ProcessPolicy()) {}

    /** @param list<string> $command */
    public function run(
        array $command,
        ?string $workingDirectory,
        float $timeoutSeconds,
        int $maximumStdoutBytes = ProcessPolicy::MAXIMUM_STDOUT_BYTES,
        int $maximumStderrBytes = ProcessPolicy::MAXIMUM_STDERR_BYTES,
    ): BoundedProcessResult {
        if ($command === [] || $timeoutSeconds <= 0.0 || $maximumStdoutBytes < 1 || $maximumStderrBytes < 1) {
            throw new \InvalidArgumentException('A bounded process requires a command and positive resource limits.');
        }

        $process = new Process(
            $command,
            $workingDirectory,
            $this->policy->environment(),
            null,
            $timeoutSeconds,
        );
        $stdout = '';
        $stderr = '';
        $overflow = false;

        try {
            $exitCode = $process->run(function (string $type, string $chunk) use (
                $process,
                &$stdout,
                &$stderr,
                &$overflow,
                $maximumStdoutBytes,
                $maximumStderrBytes,
            ): void {
                if ($overflow) {
                    return;
                }

                if ($type === Process::OUT) {
                    $overflow = !$this->appendBounded($stdout, $chunk, $maximumStdoutBytes);
                    $process->clearOutput();
                } else {
                    $overflow = !$this->appendBounded($stderr, $chunk, $maximumStderrBytes);
                    $process->clearErrorOutput();
                }

                if ($overflow) {
                    $process->stop(0.0);
                }
            });

            return new BoundedProcessResult($exitCode, $stdout, $stderr, outputLimitExceeded: $overflow);
        } catch (ProcessTimedOutException) {
            $process->stop(0.0);

            return new BoundedProcessResult(
                $process->getExitCode(),
                $stdout,
                $stderr,
                timedOut: true,
                executionFailure: 'The process exceeded its time limit.',
            );
        } catch (\Throwable $exception) {
            $process->stop(0.0);

            return new BoundedProcessResult(
                $process->getExitCode(),
                $stdout,
                $stderr,
                outputLimitExceeded: $overflow,
                executionFailure: $exception::class . ': ' . $exception->getMessage(),
            );
        }
    }

    private function appendBounded(string &$buffer, string $chunk, int $maximumBytes): bool
    {
        $remaining = $maximumBytes - strlen($buffer);

        if ($remaining <= 0) {
            return false;
        }

        if (strlen($chunk) <= $remaining) {
            $buffer .= $chunk;

            return true;
        }

        $buffer .= substr($chunk, 0, $remaining);

        return false;
    }
}
