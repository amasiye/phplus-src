<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source;

final readonly class Position
{
    public function __construct(
        public SourceFile $sourceFile,
        public int $offset,
        public int $line,
        public int $column,
    ) {
        if ($offset < 0 || $offset > $sourceFile->length()) {
            throw new \OutOfBoundsException('The position offset is outside the source file.');
        }

        if ($line < 1 || $column < 1) {
            throw new \InvalidArgumentException('Source positions use one-based lines and columns.');
        }
    }
}
