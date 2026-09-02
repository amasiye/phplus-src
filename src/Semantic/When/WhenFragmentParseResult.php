<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class WhenFragmentParseResult
{
    /** @param list<Stmt> $statements */
    public function __construct(
        public readonly ?Expr $expression,
        public readonly array $statements,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
