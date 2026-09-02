<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class ComposerResolutionResult
{
    public function __construct(
        public readonly ?ComposerProject $project,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($project === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('Composer resolution result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->project !== null && !$this->diagnostics->hasErrors;
    }
}
