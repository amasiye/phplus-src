<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\When;

use Amasiye\Ppphp\Frontend\Ast\WhenExpression;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use PhpParser\Node;
use PhpParser\Node\Expr;

final readonly class WhenExpressionAnalysis
{
    /** @param list<WhenBranchAnalysis> $branches */
    public function __construct(
        public WhenExpression $syntax,
        public WhenExpressionSite $site,
        public Expr $placeholder,
        public Node $statement,
        public array $branches,
        public LocalType $resultType,
        public string $temporaryName,
    ) {}
}
