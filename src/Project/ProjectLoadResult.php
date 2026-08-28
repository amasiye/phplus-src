<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final class ProjectLoadResult
{
    public function __construct(
        public readonly ?Project $project,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($project === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('Project load result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->project !== null && !$this->diagnostics->hasErrors;
    }
}
