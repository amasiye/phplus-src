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
        $this->emitTags($context, $owner, ['@throws ' . $expression]);
    }

    /** @param list<string> $tags */
    public function emitTags(TranspilationContext $context, Node $owner, array $tags): void
    {
        $document = $owner->getDocComment();
        $missing = [];

        foreach (array_values(array_unique($tags)) as $tag) {
            if ($document === null || !$this->containsTag($document, $tag)) {
                $missing[] = $tag;
            }
        }

        if ($missing === []) {
            return;
        }

        if ($document !== null) {
            $this->mergeTagsIntoDocument($context, $document, $missing);

            return;
        }

        $this->insertTags($context, $owner, $missing);
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

    /** @param non-empty-list<string> $tags */
    private function mergeTagsIntoDocument(
        TranspilationContext $context,
        Doc $document,
        array $tags,
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


            foreach ($tags as $tag) {
                $lines[] = ' * ' . $tag;
            }

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
            $insertion = '';

            foreach ($tags as $tag) {
                $insertion .= $closingPrefix . '* ' . $tag . $newline;
            }

            $context->replace(
                $sourceFile->createSpan($start + $lineStart, $start + $lineStart),
                $insertion,
            );

            return;
        }

        $replacement = substr($text, 0, $close);

        foreach ($tags as $tag) {
            $replacement .= $newline . $indent . ' * ' . $tag;
        }

        $replacement .= $newline . $indent . ' ' . substr($text, $close);
        $context->replace($sourceFile->createSpan($start, $end), $replacement);
    }

    /** @param non-empty-list<string> $tags */
    private function insertTags(
        TranspilationContext $context,
        Node $owner,
        array $tags,
    ): void {
        $attributeGroups = match (true) {
            $owner instanceof Stmt\ClassLike => $owner->attrGroups,
            $owner instanceof Stmt\Function_ => $owner->attrGroups,
            $owner instanceof Stmt\ClassMethod => $owner->attrGroups,
            $owner instanceof Stmt\Property => $owner->attrGroups,
            default => [],
        };
        $offset = $attributeGroups === []
            ? $owner->getStartFilePos()
            : min(array_map(
                static fn (Node\AttributeGroup $group): int => $group->getStartFilePos(),
                $attributeGroups,
            ));
        $sourceFile = $context->parsedFile->sourceFile;
        $newline = $this->resolveNewline($sourceFile->contents);
        $indent = $this->resolveIndentation($offset, $context);
        $document = '/**';

        foreach ($tags as $tag) {
            $document .= $newline . $indent . ' * ' . $tag;
        }

        $document .= $newline . $indent . ' */' . $newline . $indent;
        $context->replace($sourceFile->createSpan($offset, $offset), $document);
    }

    private function containsTag(Doc $document, string $requested): bool
    {
        $metadata = $this->phpDoc->readMetadata($document);

        if (preg_match('/^@template\s+(\S+)/', $requested, $match) === 1) {
            foreach ($metadata->templates as $template) {
                if (strcasecmp($template['name'], $match[1]) === 0) {
                    return true;
                }
            }

            return false;
        }

        if (preg_match('/^@param\s+.+\s+(\$\S+)$/', $requested, $match) === 1) {
            return isset($metadata->parameters[$match[1]]);
        }

        if (str_starts_with($requested, '@return ')) {
            return $metadata->returns !== [];
        }

        if (preg_match('/^@var\s+\S+(?:\s+(\$\S+))?$/', $requested, $match) === 1) {
            return ($match[1] ?? '') === '' ? $metadata->variables !== [] : isset($metadata->variables[$match[1]]);
        }

        foreach ([
            '@extends ' => $metadata->extends,
            '@implements ' => $metadata->implements,
            '@use ' => $metadata->uses,
            '@throws ' => $metadata->throws,
        ] as $prefix => $types) {
            if (!str_starts_with($requested, $prefix)) {
                continue;
            }

            $expected = $this->canonicalizeType(substr($requested, strlen($prefix)));

            foreach ($types as $type) {
                if ($this->canonicalizeType($type) === $expected) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function canonicalizeType(string $type): string
    {
        return strtolower(str_replace(' ', '', trim($type)));
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
