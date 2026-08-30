<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\When;

use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class WhenExpressionLocation
{
    /** @param list<Node> $ancestors nearest first */
    public function __construct(
        public WhenExpressionSite $site,
        public Expr $placeholder,
        public Node $statement,
        public ?Node $parent,
        public array $ancestors,
        public bool $fragment,
    ) {}
}
