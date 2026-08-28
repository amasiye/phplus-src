<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

final readonly class TypedLocalDeclaration implements Node
{
    public function __construct(
        public NodeId $id,
        public Span $span,
        public ?Span $readonlySpan,
        public SourceType $type,
        public Span $variableSpan,
        public Span $equalsSpan,
        public Span $initializerSpan,
        public Span $semicolonSpan,
    ) {}
}
