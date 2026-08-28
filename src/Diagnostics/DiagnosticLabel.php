<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics;

use Amasiye\Phplus\Source\Span;

final readonly class DiagnosticLabel
{
    public function __construct(
        public Span $span,
        public string $message,
    ) {
    }
}
