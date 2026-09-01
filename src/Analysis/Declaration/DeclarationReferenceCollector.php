<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Declaration;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final readonly class DeclarationReferenceCollector
{
    public function __construct(private SourceNameResolver $names = new SourceNameResolver()) {}

    /**
     * @param iterable<ParsedFile> $files
     * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
     */
    public function collect(iterable $files): array
    {
        $references = ['classes' => [], 'functions' => [], 'constants' => []];

        foreach ($files as $file) {
            foreach ($file->statements as $statement) {
                $this->visit($file, $statement, $references);
            }
        }

        $classes = array_keys($references['classes']);
        $functions = array_keys($references['functions']);
        $constants = array_keys($references['constants']);
        sort($classes, SORT_STRING);
        sort($functions, SORT_STRING);
        sort($constants, SORT_STRING);

        return [
            'classes' => $classes,
            'functions' => $functions,
            'constants' => $constants,
        ];
    }

    /**
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $references
     */
    private function visit(ParsedFile $file, Node $node, array &$references): void
    {
        if ($node instanceof Stmt\Namespace_) {
            foreach ($node->stmts as $statement) {
                $this->visit($file, $statement, $references);
            }

            return;
        }

        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            return;
        }

        if ($node instanceof Stmt\Function_) {
            $this->recordDeclaration($file, $node->name->toString(), 'functions', $node, $references);
        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->recordDeclaration($file, $node->name->toString(), 'classes', $node, $references);
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $constant) {
                $this->recordDeclaration($file, $constant->name->toString(), 'constants', $constant, $references);
            }
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->record($file, $node->name, 'functions', $references, true);
        } elseif ($node instanceof Expr\ConstFetch) {
            $name = strtolower($node->name->toString());

            if (!in_array($name, ['false', 'null', 'true'], true)) {
                $this->record($file, $node->name, 'constants', $references, true);
            }
        } elseif ($node instanceof Name) {
            $name = strtolower($node->toString());

            if (!in_array($name, ['parent', 'self', 'static'], true)) {
                $this->record($file, $node, 'classes', $references, false);
            }

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            if (($node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch) && $subNodeName === 'name') {
                continue;
            }

            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $this->visit($file, $value, $references);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->visit($file, $child, $references);
                    }
                }
            }
        }
    }

    /**
     * @param 'classes'|'functions'|'constants' $bucket
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $references
     */
    private function record(
        ParsedFile $file,
        Name $name,
        string $bucket,
        array &$references,
        bool $includeGlobalFallback,
    ): void {
        $raw = ltrim($name->toString(), '\\');
        $resolved = $this->names->resolve($file, $name->toString(), max(0, $name->getStartFilePos()));

        foreach ($includeGlobalFallback ? [$resolved, $raw] : [$resolved] as $candidate) {
            if ($candidate !== '') {
                $references[$bucket][$candidate] = true;
            }
        }
    }

    /**
     * @param 'classes'|'functions'|'constants' $bucket
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $references
     */
    private function recordDeclaration(
        ParsedFile $file,
        string $name,
        string $bucket,
        Node $node,
        array &$references,
    ): void {
        $namespace = $this->names->resolveNamespaceAt($file, max(0, $node->getStartFilePos()));
        $fullyQualifiedName = $namespace === '' ? $name : $namespace . '\\' . $name;
        $references[$bucket][$fullyQualifiedName] = true;
    }
}
