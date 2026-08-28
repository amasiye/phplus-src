<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source;

final readonly class Span
{
    public SourceFile $sourceFile;

    public function __construct(
        public Position $start,
        public Position $end,
    ) {
        if ($start->sourceFile !== $end->sourceFile) {
            throw new \InvalidArgumentException('A source span cannot cross source files.');
        }

        if ($start->offset > $end->offset) {
            throw new \InvalidArgumentException('A source span cannot end before it starts.');
        }

        $this->sourceFile = $start->sourceFile;
    }

    public function text(): string
    {
        return substr(
            $this->sourceFile->contents,
            $this->start->offset,
            $this->end->offset - $this->start->offset,
        );
    }

    public function isEmpty(): bool
    {
        return $this->start->offset === $this->end->offset;
    }
}
