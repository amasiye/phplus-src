<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ExplicitSourceLoadResult
{
    public function __construct(
        public ?ExplicitSource $source,
        public DiagnosticBag $diagnostics,
    ) {
        if ($source === null && !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException(
                'An explicit source load result without a source must contain an error diagnostic.',
            );
        }
    }

    public function isSuccessful(): bool
    {
        return $this->source !== null && !$this->diagnostics->hasErrors();
    }
}
