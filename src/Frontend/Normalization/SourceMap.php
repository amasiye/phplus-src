<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Normalization;

use Amasiye\Phplus\Source\Span;
use Amasiye\Phplus\Source\SourceFile;

final readonly class SourceMap
{
    /** @param list<SourceMapSegment> $segments */
    public function __construct(
        public SourceFile $sourceFile,
        public array $segments,
    ) {}

    public static function createIdentity(SourceFile $sourceFile): self
    {
        return new self($sourceFile, [new SourceMapSegment(0, $sourceFile->length, 0, $sourceFile->length)]);
    }

    public function resolveOriginalOffset(int $normalizedOffset): int
    {
        $this->guardOffset($normalizedOffset);

        return $normalizedOffset;
    }

    public function resolveNormalizedOffset(int $originalOffset): int
    {
        $this->guardOffset($originalOffset);

        return $originalOffset;
    }

    public function resolveOwningSpan(int $normalizedOffset): ?Span
    {
        $this->guardOffset($normalizedOffset);

        foreach ($this->segments as $segment) {
            if (
                $segment->owner !== null
                && $normalizedOffset >= $segment->normalizedStart
                && $normalizedOffset < $segment->normalizedEnd
            ) {
                return $segment->ownerSpan;
            }
        }

        return null;
    }

    private function guardOffset(int $offset): void
    {
        if ($offset < 0 || $offset > $this->sourceFile->length) {
            throw new \OutOfBoundsException('The source-map offset is outside the source file.');
        }
    }
}
