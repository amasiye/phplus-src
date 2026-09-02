<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

final readonly class PhpStanProcessResult
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
        public bool $outputLimitExceeded = false,
        public ?string $executionFailure = null,
    ) {}
}
