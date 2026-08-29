<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

final readonly class WhenExpression implements Node
{
    /** @param list<WhenBranch> $branches */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public array $branches,
        public WhenElseBranch $elseBranch,
    ) {}
}
