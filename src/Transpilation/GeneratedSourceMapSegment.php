<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Source\Span;

final readonly class GeneratedSourceMapSegment
{
    public function __construct(
        public int $generatedStart,
        public int $generatedEnd,
        public int $originalStart,
        public int $originalEnd,
        public ?Span $owner = null,
    ) {}
}
