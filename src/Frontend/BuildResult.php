<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class BuildResult
{
    public function __construct(
        public ?string $outputPath,
        public DiagnosticBag $diagnostics,
    ) {
        if ($outputPath === null && !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException(
                'A build result without an output path must contain an error diagnostic.',
            );
        }
    }

    public function isSuccessful(): bool
    {
        return $this->outputPath !== null && !$this->diagnostics->hasErrors();
    }
}
