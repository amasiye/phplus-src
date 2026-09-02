<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect;

use Atatusoft\Ppphp\Source\Span;

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
