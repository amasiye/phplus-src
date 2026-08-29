<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

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
