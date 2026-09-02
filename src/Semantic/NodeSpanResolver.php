<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Node;

final class NodeSpanResolver
{
    public function resolve(ParsedFile $parsedFile, Node $node): Span
    {
        $originalStart = $node->getAttribute('ppphpOriginalStart');
        $originalEnd = $node->getAttribute('ppphpOriginalEnd');

        if (is_int($originalStart) && is_int($originalEnd)) {
            return $parsedFile->sourceFile->createSpan($originalStart, $originalEnd);
        }

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
