<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source;

final class Span
{
    public readonly SourceFile $sourceFile;

    public function __construct(
        public readonly Position $start,
        public readonly Position $end,
    ) {
        if ($start->sourceFile !== $end->sourceFile) {
            throw new \InvalidArgumentException('A source span cannot cross source files.');
        }

        if ($start->offset > $end->offset) {
            throw new \InvalidArgumentException('A source span cannot end before it starts.');
        }

        $this->sourceFile = $start->sourceFile;
    }

    public string $text {
        get => substr(
            $this->sourceFile->contents,
            $this->start->offset,
            $this->end->offset - $this->start->offset,
        );
    }

    public bool $isEmpty {
        get => $this->start->offset === $this->end->offset;
    }
}
