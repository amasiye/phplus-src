<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Composer;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ComposerResolutionResult
{
    public function __construct(
        public ?ComposerProject $project,
        public DiagnosticBag $diagnostics,
    ) {
        if (($project === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('Composer resolution result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->project !== null && !$this->diagnostics->hasErrors();
    }
}
