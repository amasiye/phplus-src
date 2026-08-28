<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Frontend\Ast\GenericDeclaration;
use Amasiye\Phplus\Frontend\Ast\GenericType;
use Amasiye\Phplus\Frontend\Ast\ThrowsClause;
use Amasiye\Phplus\Frontend\Ast\TypedLocalDeclaration;
use Amasiye\Phplus\Frontend\Ast\WhenExpression;
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
            $parsedFile->statements,
            $parsedFile->normalizedSource->contents,
        );
        $nodes = (new NodeFinder())->findInstanceOf($parsedFile->statements, Node::class);
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

        $extensionLines = ['Extension Syntax:'];

        if ($parsedFile->extensionSyntax->isEmpty) {
            $extensionLines[] = '  (none)';
        }

        foreach ($parsedFile->extensionSyntax->nodes as $node) {
            $description = match (true) {
                $node instanceof TypedLocalDeclaration => sprintf(
                    'TypedLocal variable=%s type=%s readonly=%s',
                    $node->variableSpan->text,
                    $node->type->text,
                    $node->readonlySpan === null ? 'false' : 'true',
                ),
                $node instanceof GenericDeclaration => sprintf(
                    'GenericDeclaration owner=%s parameters=%d',
                    $node->ownerNameSpan->text,
                    count($node->parameters),
                ),
                $node instanceof GenericType => sprintf(
                    '%s name=%s arguments=%d',
                    $node->isTypedArray ? 'TypedArray' : 'GenericType',
                    $node->nameSpan->text,
                    count($node->arguments),
                ),
                $node instanceof ThrowsClause => sprintf('ThrowsClause errors=%d', count($node->errorTypes)),
                $node instanceof WhenExpression => sprintf('WhenExpression branches=%d', count($node->branches)),
                default => $node::class,
            };
            $extensionLines[] = sprintf(
                '  %s %s span=[%d,%d)',
                $node->id->value,
                $description,
                $node->span->start->offset,
                $node->span->end->offset,
            );

            if ($node instanceof TypedLocalDeclaration) {
                $extensionLines[] = sprintf(
                    '    type=[%d,%d) variable=[%d,%d) initializer=[%d,%d) semicolon=[%d,%d)',
                    $node->type->span->start->offset,
                    $node->type->span->end->offset,
                    $node->variableSpan->start->offset,
                    $node->variableSpan->end->offset,
                    $node->initializerSpan->start->offset,
                    $node->initializerSpan->end->offset,
                    $node->semicolonSpan->start->offset,
                    $node->semicolonSpan->end->offset,
                );
            } elseif ($node instanceof GenericDeclaration) {
                foreach ($node->parameters as $parameter) {
                    $extensionLines[] = sprintf(
                        '    parameter=%s span=[%d,%d) bound=%s',
                        $parameter->nameSpan->text,
                        $parameter->span->start->offset,
                        $parameter->span->end->offset,
                        $parameter->bound === null ? '(none)' : $parameter->bound->text,
                    );
                }
            } elseif ($node instanceof GenericType) {
                foreach ($node->arguments as $argument) {
                    $extensionLines[] = sprintf(
                        '    argument=%s span=[%d,%d)',
                        $argument->text,
                        $argument->span->start->offset,
                        $argument->span->end->offset,
                    );
                }
            } elseif ($node instanceof ThrowsClause) {
                foreach ($node->errorTypes as $errorType) {
                    $extensionLines[] = sprintf(
                        '    error=%s span=[%d,%d)',
                        $errorType->text,
                        $errorType->span->start->offset,
                        $errorType->span->end->offset,
                    );
                }
            } elseif ($node instanceof WhenExpression) {
                foreach ($node->branches as $branch) {
                    $extensionLines[] = sprintf(
                        '    branch condition=[%d,%d) body=[%d,%d)',
                        $branch->conditionSpan->start->offset,
                        $branch->conditionSpan->end->offset,
                        $branch->bodySpan->start->offset,
                        $branch->bodySpan->end->offset,
                    );
                }

                $extensionLines[] = sprintf(
                    '    else body=[%d,%d)',
                    $node->elseBranch->bodySpan->start->offset,
                    $node->elseBranch->bodySpan->end->offset,
                );
            }
        }

        $normalizationLines = ['Normalization:'];
        $normalizationLines[] = sprintf('  bytes=%d edits=%d', $parsedFile->sourceFile->length, count($parsedFile->normalizationPlan->edits));

        foreach ($parsedFile->normalizationPlan->edits as $edit) {
            $normalizationLines[] = sprintf(
                '  owner=%s original=[%d,%d) normalized=[%d,%d)',
                $edit->owner->value,
                $edit->span->start->offset,
                $edit->span->end->offset,
                $edit->span->start->offset,
                $edit->span->end->offset,
            );
        }

        return implode("\n", $extensionLines)
            . "\n\nNormalized PHP AST:\n"
            . $nodeDump
            . "\n\n"
            . implode("\n", $positionLines)
            . "\n\n"
            . implode("\n", $normalizationLines);
    }
}
