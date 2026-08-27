<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Config;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ProjectConfigLoadResult
{
    private function __construct(
        public ?ProjectConfig $configuration,
        public DiagnosticBag $diagnostics,
    ) {
        if ($configuration !== null && $diagnostics->hasErrors()) {
            throw new \LogicException('A valid project configuration result cannot contain errors.');
        }

        if ($configuration === null && !$diagnostics->hasErrors()) {
            throw new \LogicException('An invalid project configuration result must contain an error.');
        }
    }

    public static function success(ProjectConfig $configuration, DiagnosticBag $diagnostics): self
    {
        return new self($configuration, $diagnostics);
    }

    public static function failure(DiagnosticBag $diagnostics): self
    {
        return new self(null, $diagnostics);
    }

    public function isSuccessful(): bool
    {
        return $this->configuration !== null && !$this->diagnostics->hasErrors();
    }
}
