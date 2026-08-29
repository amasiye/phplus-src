<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\SemanticModel;
use Amasiye\Ppphp\Source\Span;

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

    public function replace(Span $span, string $replacement): void
    {
        if ($span->sourceFile !== $this->parsedFile->sourceFile) {
            throw new \InvalidArgumentException('A lowering edit must belong to the file being transpiled.');
        }

        $this->recordedEdits[] = new SourceEdit($span, $replacement);
    }

    public function generate(): GeneratedPhp
    {
        $edits = $this->recordedEdits;
        usort($edits, static fn (SourceEdit $left, SourceEdit $right): int =>
            ($left->span->start->offset <=> $right->span->start->offset)
                ?: ($left->span->end->offset <=> $right->span->end->offset));

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
                $segments[] = new GeneratedSourceMapSegment(
                    $generatedCursor,
                    $generatedCursor + $replacementLength,
                    $edit->span->start->offset,
                    $edit->span->end->offset,
                    $edit->span,
                );
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
