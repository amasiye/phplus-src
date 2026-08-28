<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ProjectSelectionResult
{
    public function __construct(
        public ?ProjectSelection $selection,
        public DiagnosticBag $diagnostics,
    ) {
        if (($selection === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('Project selection result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->selection !== null && !$this->diagnostics->hasErrors();
    }
}
