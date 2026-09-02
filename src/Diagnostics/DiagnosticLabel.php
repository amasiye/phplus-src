<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Source\Span;

final readonly class DiagnosticLabel
{
    public function __construct(
        public Span $span,
        public string $message,
    ) {
    }
}
