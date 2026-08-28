<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

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
