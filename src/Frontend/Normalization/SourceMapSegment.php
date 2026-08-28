<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Normalization;

use Amasiye\Phplus\Frontend\Ast\NodeId;
use Amasiye\Phplus\Source\Span;

final class SourceMapSegment
{
    public function __construct(
        public readonly int $originalStart,
        public readonly int $originalEnd,
        public readonly int $normalizedStart,
        public readonly int $normalizedEnd,
        public readonly ?NodeId $owner = null,
        public readonly ?Span $ownerSpan = null,
    ) {}

    public bool $isGenerated {
        get => $this->owner !== null;
    }
}
