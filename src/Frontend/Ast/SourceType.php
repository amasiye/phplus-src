<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

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
