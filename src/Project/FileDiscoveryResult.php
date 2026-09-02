<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class FileDiscoveryResult
{
    public function __construct(
        public readonly ?SourceSet $sources,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($sources === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('File discovery result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->sources !== null && !$this->diagnostics->hasErrors;
    }
}
