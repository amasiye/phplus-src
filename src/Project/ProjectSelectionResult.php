<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final class ProjectSelectionResult
{
    public function __construct(
        public readonly ?ProjectSelection $selection,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($selection === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('Project selection result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->selection !== null && !$this->diagnostics->hasErrors;
    }
}
