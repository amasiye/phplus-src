<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use PhpParser\Node;
use PhpParser\NodeDumper;
use PhpParser\NodeFinder;

final readonly class AstDumper
{
    public function dump(ParsedFile $parsedFile): string
    {
        $dumper = new NodeDumper([
            'dumpComments' => true,
            'dumpPositions' => true,
            'dumpOtherAttributes' => true,
        ]);
        $nodeDump = $dumper->dump(
            $parsedFile->statements(),
            $parsedFile->sourceFile->contents,
        );
        $nodes = (new NodeFinder())->findInstanceOf($parsedFile->statements(), Node::class);
        $positionLines = ['Position Attributes:'];

        foreach ($nodes as $index => $node) {
            $positionLines[] = sprintf(
                '  %d %s startLine=%d endLine=%d startFilePos=%d endFilePos=%d startTokenPos=%d endTokenPos=%d',
                $index,
                $node->getType(),
                $node->getStartLine(),
                $node->getEndLine(),
                $node->getStartFilePos(),
                $node->getEndFilePos(),
                $node->getStartTokenPos(),
                $node->getEndTokenPos(),
            );
        }

        return $nodeDump . "\n\n" . implode("\n", $positionLines);
    }
}
