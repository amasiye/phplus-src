<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Enumerations\ForeachBindingPosition;
use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

final readonly class TypedForeachBinding implements Node
{
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $loopKeywordSpan,
        public SourceType $type,
        public Span $variableSpan,
        public ForeachBindingPosition $position,
    ) {}
}
