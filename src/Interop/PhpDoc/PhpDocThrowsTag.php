<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\PhpDoc;

use Amasiye\Ppphp\Source\Span;

final readonly class PhpDocThrowsTag
{
    public function __construct(
        public string $typeExpression,
        public Span $typeSpan,
        public Span $documentSpan,
        public string $description = '',
    ) {}
}
