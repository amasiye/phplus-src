<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

final readonly class WhenBranch implements Node
{
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $whenKeywordSpan,
        public Span $conditionSpan,
        public Span $bodySpan,
        public ?Span $elseKeywordSpan = null,
    ) {}
}
