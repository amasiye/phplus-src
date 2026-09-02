<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\WhenBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
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
