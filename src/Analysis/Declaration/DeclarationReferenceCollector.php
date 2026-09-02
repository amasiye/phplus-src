<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Declaration;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeParser;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\Type\IntersectionType;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final readonly class DeclarationReferenceCollector
{
    public function __construct(
        private SourceNameResolver $names = new SourceNameResolver(),
        private PhpDocReader $phpDoc = new PhpDocReader(),
        private CompositeTypeParser $types = new CompositeTypeParser(),
    ) {}

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

        return $this->sorted($references);
    }

    /**
     * @param iterable<ParsedFile> $files
     * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
     */
    public function collectDeclarations(iterable $files): array
    {
        $declarations = ['classes' => [], 'functions' => [], 'constants' => []];

        foreach ($files as $file) {
            foreach ($file->statements as $statement) {
                $this->visitDeclarations($file, $statement, $declarations);
            }
        }

        return $this->sorted($declarations);
    }

    /**
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $names
     * @return array{classes: list<string>, functions: list<string>, constants: list<string>}
     */
    private function sorted(array $names): array
    {
        $classes = array_keys($names['classes']);
        $functions = array_keys($names['functions']);
        $constants = array_keys($names['constants']);
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
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $declarations
     */
    private function visitDeclarations(ParsedFile $file, Node $node, array &$declarations): void
    {
        if ($node instanceof Stmt\Namespace_) {
            foreach ($node->stmts as $statement) {
                $this->visitDeclarations($file, $statement, $declarations);
            }

            return;
        }

        if ($node instanceof Stmt\Function_) {
            $this->recordDeclaration($file, $node->name->toString(), 'functions', $node, $declarations);
        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->recordDeclaration($file, $node->name->toString(), 'classes', $node, $declarations);
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $constant) {
                $this->recordDeclaration($file, $constant->name->toString(), 'constants', $constant, $declarations);
            }
        }
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

        $this->recordDocumentedTypes($file, $node, $references);

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
        $resolved = $name->isFullyQualified()
            ? $raw
            : $this->names->resolve($file, $name->toString(), max(0, $name->getStartFilePos()));

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

    /**
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $references
     */
    private function recordDocumentedTypes(ParsedFile $file, Node $node, array &$references): void
    {
        $document = $node->getDocComment();

        if ($document === null) {
            return;
        }

        $metadata = $this->phpDoc->readMetadata($document);
        $templates = array_fill_keys(array_map(
            static fn (array $template): string => strtolower($template['name']),
            $metadata->templates,
        ), true);
        $types = [
            ...array_values(array_filter(array_column($metadata->templates, 'bound'), is_string(...))),
            ...array_values($metadata->parameters),
            ...$metadata->returns,
            ...array_values($metadata->variables),
            ...$metadata->extends,
            ...$metadata->implements,
            ...$metadata->uses,
            ...$metadata->throws,
        ];

        foreach ($types as $type) {
            $normalized = preg_replace('/\blist\s*</i', 'array<', $type) ?? $type;
            $normalized = strcasecmp(trim($normalized), 'array-key') === 0 ? 'int|string' : $normalized;
            $this->recordType(
                $file,
                $this->types->parse($normalized),
                max(0, $node->getStartFilePos()),
                $templates,
                $references,
            );
        }
    }

    /**
     * @param array<string, true> $templates
     * @param array{classes: array<string, true>, functions: array<string, true>, constants: array<string, true>} $references
     */
    private function recordType(
        ParsedFile $file,
        Type $type,
        int $offset,
        array $templates,
        array &$references,
    ): void {
        if ($type instanceof AtomicType) {
            if (!$type->isBuiltin
                && !isset($templates[strtolower($type->name)])
                && !in_array($type->canonical, ['parent', 'self', 'static'], true)) {
                $references['classes'][$this->names->resolve($file, $type->renderPhpDoc(), $offset)] = true;
            }

            return;
        }

        if ($type instanceof GenericType) {
            $this->recordType($file, $type->base, $offset, $templates, $references);

            foreach ($type->arguments as $argument) {
                $this->recordType($file, $argument, $offset, $templates, $references);
            }

            return;
        }

        if ($type instanceof TypedArrayType) {
            $this->recordType($file, $type->keyType, $offset, $templates, $references);
            $this->recordType($file, $type->valueType, $offset, $templates, $references);
            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->members as $member) {
                $this->recordType($file, $member, $offset, $templates, $references);
            }
        }
    }
}
