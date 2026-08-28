<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final class BuildResult
{
    public function __construct(
        public readonly ?string $outputPath,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if ($outputPath === null && !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException(
                'A build result without an output path must contain an error diagnostic.',
            );
        }
    }

    public bool $isSuccessful {
        get => $this->outputPath !== null && !$this->diagnostics->hasErrors;
    }
}
