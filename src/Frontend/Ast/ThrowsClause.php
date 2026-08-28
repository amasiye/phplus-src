<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Frontend\Ast\Interfaces\Node;
use Amasiye\Phplus\Source\Span;

final readonly class ThrowsClause implements Node
{
    /**
     * @param list<SourceType> $errorTypes
     * @param list<Span> $separatorSpans
     */
    public function __construct(
        public NodeId $id,
        public Span $span,
        public Span $keywordSpan,
        public array $errorTypes,
        public array $separatorSpans,
    ) {}
}
