<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

final readonly class GenericDeclaration implements Node
{
    /** @param list<GenericParameter> $parameters */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public string $declarationKind,
        public Span $ownerNameSpan,
        public array $parameters,
    ) {}
}
