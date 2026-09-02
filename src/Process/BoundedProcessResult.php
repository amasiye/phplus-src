<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Process;

final readonly class BoundedProcessResult
{
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
        public bool $outputLimitExceeded = false,
        public ?string $executionFailure = null,
    ) {}
}
