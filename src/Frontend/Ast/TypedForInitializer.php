<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

final readonly class TypedForInitializer implements Node
{
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $loopKeywordSpan,
        public ?Span $readonlySpan,
        public SourceType $type,
        public Span $variableSpan,
        public Span $equalsSpan,
        public Span $initializerSpan,
    ) {}
}
