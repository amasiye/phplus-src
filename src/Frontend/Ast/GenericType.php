<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

final readonly class GenericType implements Node
{
    /** @param list<SourceType> $arguments */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $nameSpan,
        public Span $argumentListSpan,
        public array $arguments,
        public bool $isTypedArray,
    ) {}
}
