<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

use Atatusoft\Ppphp\Frontend\Ast\WhenExpression;

final readonly class WhenParsedExpression
{
    /** @param list<WhenParsedBranch> $branches */
    public function __construct(
        public WhenExpression $syntax,
        public array $branches,
    ) {}
}
