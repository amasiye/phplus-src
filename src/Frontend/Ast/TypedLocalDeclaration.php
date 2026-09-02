<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

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
