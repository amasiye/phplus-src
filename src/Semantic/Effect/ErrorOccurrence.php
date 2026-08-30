<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Source\Span;

final class ErrorOccurrence
{
    public function __construct(
        public readonly string $type,
        public readonly Span $span,
        public readonly ?Span $declarationSpan = null,
    ) {}

    public string $canonicalType {
        get => ltrim($this->type, '\\');
    }
}
