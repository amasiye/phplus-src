<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Validation;

final class PhpLintResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly bool $timedOut = false,
        public readonly ?string $executionFailure = null,
    ) {}

    public bool $isSuccessful {
        get => $this->exitCode === 0 && !$this->timedOut && $this->executionFailure === null;
    }
}
