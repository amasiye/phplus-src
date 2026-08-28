<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ProjectLoadResult
{
    public function __construct(
        public ?Project $project,
        public DiagnosticBag $diagnostics,
    ) {
        if (($project === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('Project load result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->project !== null && !$this->diagnostics->hasErrors();
    }
}
