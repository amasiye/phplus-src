<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Source\Span;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;

final readonly class AnalysisSourceMap
{
    public function __construct(
        public string $analysisPath,
        public string $generatedContents,
        public GeneratedSourceMap $generatedSourceMap,
    ) {}

    public function resolveSpan(int $line, ?int $column = null): Span
    {
        $lineStarts = [0];
        $length = strlen($this->generatedContents);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($this->generatedContents[$offset] === "\n") {
                $lineStarts[] = $offset + 1;
            }
        }

        $lineIndex = max(0, min(count($lineStarts) - 1, $line - 1));
        $generatedOffset = $lineStarts[$lineIndex] + max(0, ($column ?? 1) - 1);
        $generatedOffset = min($generatedOffset, $length);
        $originalOffset = $this->generatedSourceMap->resolveOriginalOffset($generatedOffset);
        $sourceFile = $this->generatedSourceMap->sourceFile;
        $end = min($sourceFile->length, $originalOffset + ($originalOffset < $sourceFile->length ? 1 : 0));

        return $sourceFile->createSpan($originalOffset, $end);
    }
}
