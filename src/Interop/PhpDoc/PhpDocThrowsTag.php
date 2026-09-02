<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\PhpDoc;

use Atatusoft\Ppphp\Source\Span;

final readonly class PhpDocThrowsTag
{
    public function __construct(
        public string $typeExpression,
        public Span $typeSpan,
        public Span $documentSpan,
        public string $description = '',
    ) {}
}
