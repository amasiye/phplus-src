<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final class ProjectCleanupResult
{
    /** @param list<string> $paths */
    public function __construct(
        public readonly array $paths,
        public readonly DiagnosticBag $diagnostics,
    ) {
    }

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
