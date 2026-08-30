<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\When;

use Amasiye\Ppphp\Frontend\Ast\WhenBranch;
use Amasiye\Ppphp\Frontend\Ast\WhenElseBranch;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Source\Span;
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
