<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Normalization;

use Amasiye\Ppphp\Source\SourceFile;

final readonly class NormalizationPlan
{
    /** @var list<NormalizationEdit> */
    public array $edits;

    /** @param list<NormalizationEdit> $edits */
    public function __construct(public SourceFile $sourceFile, array $edits = [])
    {
        usort($edits, static fn (NormalizationEdit $left, NormalizationEdit $right): int =>
            ($left->span->start->offset <=> $right->span->start->offset)
                ?: ($left->span->end->offset <=> $right->span->end->offset)
                ?: ($left->owner->value <=> $right->owner->value));

        $previousEnd = 0;

        foreach ($edits as $edit) {
            if ($edit->span->sourceFile !== $sourceFile) {
                throw new \InvalidArgumentException('A normalization edit belongs to another source file.');
            }

            if ($edit->span->start->offset < $previousEnd) {
                throw new \DomainException('Normalization edits overlap.');
            }

            $previousEnd = $edit->span->end->offset;
        }

        $this->edits = $edits;
    }

    public function normalize(): NormalizedSource
    {
        $contents = $this->sourceFile->contents;
        $segments = [];
        $cursor = 0;

        foreach ($this->edits as $edit) {
            $start = $edit->span->start->offset;
            $end = $edit->span->end->offset;

            if ($cursor < $start) {
                $segments[] = new SourceMapSegment($cursor, $start, $cursor, $start);
            }

            $contents = substr_replace($contents, $edit->replacement, $start, $end - $start);
            $segments[] = new SourceMapSegment($start, $end, $start, $end, $edit->owner, $edit->span);
            $cursor = $end;
        }

        if ($cursor < $this->sourceFile->length || $segments === []) {
            $segments[] = new SourceMapSegment(
                $cursor,
                $this->sourceFile->length,
                $cursor,
                $this->sourceFile->length,
            );
        }

        return new NormalizedSource(
            $this->sourceFile,
            $contents,
            new SourceMap($this->sourceFile, $segments),
        );
    }
}
