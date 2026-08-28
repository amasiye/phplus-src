<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

final readonly class GenericParameter implements Node
{
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $nameSpan,
        public ?Span $colonSpan,
        public ?SourceType $bound,
    ) {}
}
