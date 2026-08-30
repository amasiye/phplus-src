<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Enumerations\ForeachBindingPosition;
use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

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
