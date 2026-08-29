<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Source\Span;

final readonly class DiagnosticLabel
{
    public function __construct(
        public Span $span,
        public string $message,
    ) {
    }
}
