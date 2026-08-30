<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\When;

use Amasiye\Ppphp\Frontend\Ast\WhenExpression;

final readonly class WhenParsedExpression
{
    /** @param list<WhenParsedBranch> $branches */
    public function __construct(
        public WhenExpression $syntax,
        public array $branches,
    ) {}
}
