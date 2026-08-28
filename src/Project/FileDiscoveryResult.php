<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class FileDiscoveryResult
{
    public function __construct(
        public ?SourceSet $sources,
        public DiagnosticBag $diagnostics,
    ) {
        if (($sources === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('File discovery result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->sources !== null && !$this->diagnostics->hasErrors();
    }
}
