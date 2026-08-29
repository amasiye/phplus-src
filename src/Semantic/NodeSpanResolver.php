<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;

final class NodeSpanResolver
{
    public function resolve(ParsedFile $parsedFile, Node $node): Span
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return $parsedFile->sourceFile->createSpan(0, 0);
        }

        $normalizedStart = min($start, $parsedFile->sourceFile->length);
        $normalizedEnd = min($end + 1, $parsedFile->sourceFile->length);
        $originalStart = $parsedFile->sourceMap->resolveOriginalOffset($normalizedStart);
        $originalEnd = $parsedFile->sourceMap->resolveOriginalOffset($normalizedEnd);

        return $parsedFile->sourceFile->createSpan($originalStart, max($originalStart, $originalEnd));
    }
}
