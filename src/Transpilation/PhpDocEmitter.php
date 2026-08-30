<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Frontend\Ast\ThrowsClause;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class PhpDocEmitter
{
    public function __construct(private PhpDocReader $phpDoc = new PhpDocReader()) {}

    public function emit(
        TranspilationContext $context,
        ThrowsClause $clause,
        CallableErrorContract $contract,
    ): void {
        $owner = $this->findOwner($context->parsedFile->statements, $clause);

        if ($owner === null) {
            throw new \LogicException(sprintf(
                'The throws clause owned by %s cannot be emitted without its exact callable.',
                $clause->ownerNameSpan->text,
            ));
        }

        $types = [];

        foreach ($contract->declaredErrors as $error) {
            $canonical = '\\' . ltrim($error->canonicalType, '\\');
            $key = strtolower($canonical);

            if (!isset($types[$key])) {
                $types[$key] = $canonical;
            }
        }

        if ($types === []) {
            throw new \LogicException('A native throws clause cannot lower to an empty error contract.');
        }

        $expression = implode('|', array_values($types));
        $document = $owner->getDocComment();

        if ($document !== null) {
            if ($this->phpDoc->readThrows($document, $context->parsedFile->sourceFile) !== []) {
                return;
            }

            $this->mergeIntoDocument($context, $document, $expression);

            return;
        }

        $this->insertDocument($context, $owner, $expression);
    }

    /**
     * @param list<Stmt> $statements
     * @return Stmt\Function_|Stmt\ClassMethod|null
     */
    private function findOwner(array $statements, ThrowsClause $clause): Stmt\Function_|Stmt\ClassMethod|null
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $owner = $this->findOwner(array_values($statement->stmts), $clause);

                if ($owner !== null) {
                    return $owner;
                }

                continue;
            }

            if ($statement instanceof Stmt\Function_ && $this->matchesOwner($statement, $clause)) {
                return $statement;
            }

            if (!$statement instanceof Stmt\ClassLike) {
                continue;
            }

            foreach ($statement->getMethods() as $method) {
                if ($this->matchesOwner($method, $clause)) {
                    return $method;
                }
            }
        }

        return null;
    }

    private function matchesOwner(
        Stmt\Function_|Stmt\ClassMethod $owner,
        ThrowsClause $clause,
    ): bool {
        return $owner->name->getStartFilePos() === $clause->ownerNameSpan->start->offset
            && $owner->name->getEndFilePos() + 1 === $clause->ownerNameSpan->end->offset
            && $owner->name->toString() === $clause->ownerNameSpan->text;
    }

    private function mergeIntoDocument(
        TranspilationContext $context,
        Doc $document,
        string $expression,
    ): void {
        $sourceFile = $context->parsedFile->sourceFile;
        $text = $document->getText();
        $start = $document->getStartFilePos();
        $end = $document->getEndFilePos() + 1;
        $newline = $this->resolveNewline($sourceFile->contents);
        $indent = $this->resolveIndentation($start, $context);
        $close = strrpos($text, '*/');

        if ($close === false) {
            throw new \LogicException('A callable PHPDoc block does not contain a closing marker.');
        }

        if (!str_contains($text, "\n") && !str_contains($text, "\r")) {
            $summary = trim(substr($text, 3, $close - 3));
            $lines = ['/**'];

            if ($summary !== '') {
                $lines[] = ' * ' . $summary;
            }

            $lines[] = ' * @throws ' . $expression;
            $lines[] = ' */';
            $replacement = array_shift($lines);

            foreach ($lines as $line) {
                $replacement .= $newline . $indent . $line;
            }

            $context->replace($sourceFile->createSpan($start, $end), $replacement);

            return;
        }

        $beforeClose = substr($text, 0, $close);
        $lineBreak = strrpos($beforeClose, "\n");
        $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;
        $closingPrefix = substr($text, $lineStart, $close - $lineStart);

        if (trim($closingPrefix) === '') {
            $insertion = $closingPrefix . '* @throws ' . $expression . $newline;
            $context->replace(
                $sourceFile->createSpan($start + $lineStart, $start + $lineStart),
                $insertion,
            );

            return;
        }

        $replacement = substr($text, 0, $close)
            . $newline
            . $indent
            . ' * @throws '
            . $expression
            . $newline
            . $indent
            . ' '
            . substr($text, $close);
        $context->replace($sourceFile->createSpan($start, $end), $replacement);
    }

    private function insertDocument(
        TranspilationContext $context,
        Stmt\Function_|Stmt\ClassMethod $owner,
        string $expression,
    ): void {
        $offset = $owner->attrGroups === []
            ? $owner->getStartFilePos()
            : min(array_map(
                static fn (Node\AttributeGroup $group): int => $group->getStartFilePos(),
                $owner->attrGroups,
            ));
        $sourceFile = $context->parsedFile->sourceFile;
        $newline = $this->resolveNewline($sourceFile->contents);
        $indent = $this->resolveIndentation($offset, $context);
        $document = '/**'
            . $newline
            . $indent
            . ' * @throws '
            . $expression
            . $newline
            . $indent
            . ' */'
            . $newline
            . $indent;
        $context->replace($sourceFile->createSpan($offset, $offset), $document);
    }

    private function resolveNewline(string $contents): string
    {
        return str_contains($contents, "\r\n") ? "\r\n" : "\n";
    }

    private function resolveIndentation(int $offset, TranspilationContext $context): string
    {
        $source = $context->parsedFile->sourceFile->contents;
        $lineStart = max(
            strrpos(substr($source, 0, $offset), "\n") ?: -1,
            strrpos(substr($source, 0, $offset), "\r") ?: -1,
        ) + 1;
        $prefix = substr($source, $lineStart, $offset - $lineStart);

        return trim($prefix) === '' ? $prefix : '';
    }
}
