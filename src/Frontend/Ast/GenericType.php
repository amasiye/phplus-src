<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

final readonly class GenericType implements Node
{
    /** @param list<SourceType> $arguments */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $nameSpan,
        public Span $argumentListSpan,
        public array $arguments,
        public bool $isTypedArray,
    ) {}
}
