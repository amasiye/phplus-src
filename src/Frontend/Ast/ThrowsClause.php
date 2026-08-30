<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Ast;

use Amasiye\Ppphp\Frontend\Ast\Interfaces\Node;
use Amasiye\Ppphp\Source\Span;

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
        public string $ownerKind,
        public Span $ownerNameSpan,
        public Span $ownerDeclarationSpan,
        public array $errorTypes,
        public array $separatorSpans,
    ) {}
}
