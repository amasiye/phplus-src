<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

final readonly class WhenExpression implements Node
{
    /** @param list<WhenBranch> $branches */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public array $branches,
        public WhenElseBranch $elseBranch,
        public ?NodeId $parentId = null,
        public int $depth = 0,
    ) {}
}
