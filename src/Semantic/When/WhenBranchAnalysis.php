<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\WhenBranch;
use Atatusoft\Ppphp\Frontend\Ast\WhenElseBranch;
use Atatusoft\Ppphp\Semantic\Type\LocalType;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final readonly class WhenBranchAnalysis
{
    /**
     * @param list<Stmt> $statements
     * @param list<Span> $resultSpans
     */
    public function __construct(
        public WhenBranch|WhenElseBranch $syntax,
        public ?Expr $condition,
        public array $statements,
        public LocalType $resultType,
        public array $resultSpans,
        public bool $canComplete,
    ) {}
}
