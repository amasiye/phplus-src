<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ProjectCleanupResult
{
    /** @param list<string> $paths */
    public function __construct(
        public array $paths,
        public DiagnosticBag $diagnostics,
    ) {
    }

    public function isSuccessful(): bool
    {
        return !$this->diagnostics->hasErrors();
    }
}
