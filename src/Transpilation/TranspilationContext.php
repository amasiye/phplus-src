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

    public function generate(): string
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

        $contents = $this->parsedFile->sourceFile->contents;

        foreach (array_reverse($edits) as $edit) {
            $contents = substr($contents, 0, $edit->span->start->offset)
                . $edit->replacement
                . substr($contents, $edit->span->end->offset);
        }

        return $contents;
    }
}
