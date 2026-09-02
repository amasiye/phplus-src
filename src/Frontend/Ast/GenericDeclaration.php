<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

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
