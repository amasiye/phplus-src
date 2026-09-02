<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast;

use Atatusoft\Ppphp\Frontend\Ast\Interfaces\Node;
use Atatusoft\Ppphp\Source\Span;

final readonly class SourceType implements Node
{
    /** @param list<GenericType> $genericReferences */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public string $text,
        public array $genericReferences = [],
    ) {}
}
