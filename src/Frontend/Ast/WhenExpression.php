<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

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
