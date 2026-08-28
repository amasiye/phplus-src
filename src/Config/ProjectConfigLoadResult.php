<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Config;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final class ProjectConfigLoadResult
{
    private function __construct(
        public readonly ?ProjectConfig $configuration,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if ($configuration !== null && $diagnostics->hasErrors) {
            throw new \LogicException('A valid project configuration result cannot contain errors.');
        }

        if ($configuration === null && !$diagnostics->hasErrors) {
            throw new \LogicException('An invalid project configuration result must contain an error.');
        }
    }

    public static function createSuccess(ProjectConfig $configuration, DiagnosticBag $diagnostics): self
    {
        return new self($configuration, $diagnostics);
    }

    public static function createFailure(DiagnosticBag $diagnostics): self
    {
        return new self(null, $diagnostics);
    }

    public bool $isSuccessful {
        get => $this->configuration !== null && !$this->diagnostics->hasErrors;
    }
}
