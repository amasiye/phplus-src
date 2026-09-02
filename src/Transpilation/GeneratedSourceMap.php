<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Source\SourceFile;

final readonly class GeneratedSourceMap
{
    /** @param list<GeneratedSourceMapSegment> $segments */
    public function __construct(
        public SourceFile $sourceFile,
        public int $generatedLength,
        public array $segments,
    ) {}

    public static function createIdentity(SourceFile $sourceFile): self
    {
        return new self($sourceFile, $sourceFile->length, [
            new GeneratedSourceMapSegment(0, $sourceFile->length, 0, $sourceFile->length),
        ]);
    }

    public function resolveOriginalOffset(int $generatedOffset): int
    {
        if ($generatedOffset < 0 || $generatedOffset > $this->generatedLength) {
            throw new \OutOfBoundsException('The generated offset is outside the source map.');
        }

        if ($generatedOffset === $this->generatedLength) {
            return $this->sourceFile->length;
        }

        foreach ($this->segments as $segment) {
            if ($generatedOffset < $segment->generatedStart || $generatedOffset >= $segment->generatedEnd) {
                continue;
            }

            if ($segment->owner !== null) {
                return $segment->owner->start->offset;
            }

            return $segment->originalStart + min(
                $generatedOffset - $segment->generatedStart,
                $segment->originalEnd - $segment->originalStart,
            );
        }

        return min($generatedOffset, $this->sourceFile->length);
    }
}
