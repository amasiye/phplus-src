<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\When;

use Amasiye\Ppphp\Frontend\Ast\WhenBranch;
use Amasiye\Ppphp\Frontend\Ast\WhenElseBranch;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final readonly class WhenParsedBranch
{
    /** @param list<Stmt> $statements */
    public function __construct(
        public WhenBranch|WhenElseBranch $syntax,
        public ?Expr $condition,
        public array $statements,
    ) {}
}
