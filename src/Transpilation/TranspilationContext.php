<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\SemanticModel;
use Atatusoft\Ppphp\Source\Span;

final class TranspilationContext
{
    /** @var list<SourceEdit> */
    private array $recordedEdits = [];

    public function __construct(
        public readonly ParsedFile $parsedFile,
        public readonly SemanticModel $semanticModel,
    ) {}

    /** @var list<SourceEdit> */
    public array $sourceEdits {
        get => $this->recordedEdits;
    }

    /** @param list<SourceEditMapping> $mappings */
    public function replace(Span $span, string $replacement, array $mappings = []): void
    {
        if ($span->sourceFile !== $this->parsedFile->sourceFile) {
            throw new \InvalidArgumentException('A lowering edit must belong to the file being transpiled.');
        }

        $replacementLength = strlen($replacement);
        $previousEnd = 0;
        foreach ($mappings as $mapping) {
            if ($mapping->origin->sourceFile !== $this->parsedFile->sourceFile) {
                throw new \InvalidArgumentException('A source-edit mapping must belong to the file being transpiled.');
            }
            if ($mapping->replacementStart < $previousEnd || $mapping->replacementEnd > $replacementLength) {
                throw new \InvalidArgumentException('Source-edit mappings must be ordered, non-overlapping, and within the replacement.');
            }
            $previousEnd = $mapping->replacementEnd;
        }

        $this->recordedEdits[] = new SourceEdit($span, $replacement, $mappings);
    }

    public function generate(): GeneratedPhp
    {
        $edits = $this->recordedEdits;
        usort($edits, static fn (SourceEdit $left, SourceEdit $right): int =>
            ($left->span->start->offset <=> $right->span->start->offset)
                ?: ($right->span->end->offset <=> $left->span->end->offset));
        $resolved = [];

        foreach ($edits as $edit) {
            $previousKey = array_key_last($resolved);
            $previous = $previousKey === null ? null : $resolved[$previousKey];

            if ($previous !== null && $edit->span->start->offset < $previous->span->end->offset) {
                if ($edit->span->end->offset <= $previous->span->end->offset) {
                    continue;
                }

                throw new \LogicException('Lowering edits overlap without an owning outer edit.');
            }

            $resolved[] = $edit;
        }

        $edits = $resolved;

        $previousEnd = 0;

        foreach ($edits as $edit) {
            if ($edit->span->start->offset < $previousEnd) {
                throw new \LogicException('Lowering edits cannot overlap.');
            }

            $previousEnd = $edit->span->end->offset;
        }

        $sourceFile = $this->parsedFile->sourceFile;
        $contents = '';
        $segments = [];
        $originalCursor = 0;
        $generatedCursor = 0;

        foreach ($edits as $edit) {
            if ($edit->span->start->offset > $originalCursor) {
                $unchanged = substr(
                    $sourceFile->contents,
                    $originalCursor,
                    $edit->span->start->offset - $originalCursor,
                );
                $contents .= $unchanged;
                $length = strlen($unchanged);
                $segments[] = new GeneratedSourceMapSegment(
                    $generatedCursor,
                    $generatedCursor + $length,
                    $originalCursor,
                    $edit->span->start->offset,
                );
                $generatedCursor += $length;
            }

            $contents .= $edit->replacement;
            $replacementLength = strlen($edit->replacement);

            if ($replacementLength > 0) {
                $replacementCursor = 0;
                foreach ($edit->mappings as $mapping) {
                    if ($mapping->replacementStart > $replacementCursor) {
                        $segments[] = new GeneratedSourceMapSegment(
                            $generatedCursor + $replacementCursor,
                            $generatedCursor + $mapping->replacementStart,
                            $edit->span->start->offset,
                            $edit->span->end->offset,
                            $edit->span,
                        );
                    }
                    if ($mapping->replacementEnd > $mapping->replacementStart) {
                        $segments[] = new GeneratedSourceMapSegment(
                            $generatedCursor + $mapping->replacementStart,
                            $generatedCursor + $mapping->replacementEnd,
                            $mapping->origin->start->offset,
                            $mapping->origin->end->offset,
                            $mapping->origin,
                        );
                    }
                    $replacementCursor = $mapping->replacementEnd;
                }
                if ($replacementCursor < $replacementLength) {
                    $segments[] = new GeneratedSourceMapSegment(
                        $generatedCursor + $replacementCursor,
                        $generatedCursor + $replacementLength,
                        $edit->span->start->offset,
                        $edit->span->end->offset,
                        $edit->span,
                    );
                }
            }

            $generatedCursor += $replacementLength;
            $originalCursor = $edit->span->end->offset;
        }

        if ($originalCursor < $sourceFile->length) {
            $unchanged = substr($sourceFile->contents, $originalCursor);
            $contents .= $unchanged;
            $length = strlen($unchanged);
            $segments[] = new GeneratedSourceMapSegment(
                $generatedCursor,
                $generatedCursor + $length,
                $originalCursor,
                $sourceFile->length,
            );
            $generatedCursor += $length;
        }

        return new GeneratedPhp(
            $contents,
            new GeneratedSourceMap($sourceFile, $generatedCursor, $segments),
            $edits,
        );
    }
}
