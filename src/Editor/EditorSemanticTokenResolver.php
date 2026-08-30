<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Frontend\Ast\SourceType;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Produces platform-neutral semantic roles from the compiler AST.
 *
 * PHP lexical highlighting remains the editor baseline. These tokens supply the
 * symbol roles that require syntax awareness and cover both PHP and ++PHP nodes.
 */
final class EditorSemanticTokenResolver
{
    /** @var list<string> */
    private const array PHP_NATIVE_TYPES = [
        'array',
        'bool',
        'callable',
        'false',
        'float',
        'int',
        'iterable',
        'mixed',
        'never',
        'null',
        'object',
        'parent',
        'self',
        'static',
        'string',
        'true',
        'void',
    ];

    /** @var array<string, array{priority: int, token: EditorSemanticToken}> */
    private array $tokens = [];

    public function __construct(
        private readonly NodeSpanResolver $spans = new NodeSpanResolver(),
        private readonly PhpLanguageSemanticTokenClassifier $phpLanguageTokens = new PhpLanguageSemanticTokenClassifier(),
    ) {}

    /** @return list<EditorSemanticToken> */
    public function resolve(ParsedFile $parsedFile): array
    {
        $this->tokens = [];

        foreach ($this->phpLanguageTokens->classify($parsedFile->tokens) as $token) {
            $this->add($token->range, $token->type, $token->modifiers);
        }

        foreach ($parsedFile->statements as $statement) {
            $this->visit($statement, $parsedFile);
        }

        $this->addExtensionSyntax($parsedFile);
        $tokens = array_column($this->tokens, 'token');
        usort($tokens, static fn (EditorSemanticToken $left, EditorSemanticToken $right): int =>
            [$left->range->start->offset, $left->range->end->offset]
            <=>
            [$right->range->start->offset, $right->range->end->offset]);

        return $tokens;
    }

    private function visit(Node $node, ParsedFile $parsedFile): void
    {
        if ($node instanceof Stmt\Namespace_ && $node->name !== null) {
            $this->addIdentifiers($this->spans->resolve($parsedFile, $node->name), 'namespace');
        } elseif ($node instanceof Stmt\Class_) {
            $this->addDeclarationName($node->name, 'class', $parsedFile, ['declaration']);
            $this->addType($node->extends, $parsedFile, 'class');

            foreach ($node->implements as $type) {
                $this->addType($type, $parsedFile, 'interface');
            }
        } elseif ($node instanceof Stmt\Interface_) {
            $this->addDeclarationName($node->name, 'interface', $parsedFile, ['declaration']);

            foreach ($node->extends as $type) {
                $this->addType($type, $parsedFile, 'interface');
            }
        } elseif ($node instanceof Stmt\Trait_) {
            $this->addDeclarationName($node->name, 'class', $parsedFile, ['declaration']);
        } elseif ($node instanceof Stmt\Enum_) {
            $this->addDeclarationName($node->name, 'enum', $parsedFile, ['declaration']);
            $this->addType($node->scalarType, $parsedFile);

            foreach ($node->implements as $type) {
                $this->addType($type, $parsedFile, 'interface');
            }
        } elseif ($node instanceof Stmt\Function_) {
            $this->addNode($node->name, 'function', $parsedFile, ['declaration']);
            $this->addFunctionLikeTypes($node, $parsedFile);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $modifiers = ['declaration'];

            if ($node->isStatic()) {
                $modifiers[] = 'static';
            }

            if ($node->isAbstract()) {
                $modifiers[] = 'abstract';
            }

            $this->addNode($node->name, 'method', $parsedFile, $modifiers);
            $this->addFunctionLikeTypes($node, $parsedFile);
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->addFunctionLikeTypes($node, $parsedFile);
        } elseif ($node instanceof Node\Param) {
            $this->addType($node->type, $parsedFile);
            $role = $node->isPromoted() ? 'property' : 'parameter';
            $modifiers = ['declaration'];

            if ($node->isPromoted() && $node->isReadonly()) {
                $modifiers[] = 'readonly';
            }

            $this->addNode($node->var, $role, $parsedFile, $modifiers);
        } elseif ($node instanceof Stmt\Property) {
            $this->addType($node->type, $parsedFile);

            foreach ($node->props as $property) {
                $modifiers = ['declaration'];

                if ($node->isStatic()) {
                    $modifiers[] = 'static';
                }

                if ($node->isReadonly()) {
                    $modifiers[] = 'readonly';
                }

                $this->addNode($property->name, 'property', $parsedFile, $modifiers);
            }
        } elseif ($node instanceof Stmt\EnumCase) {
            $this->addNode($node->name, 'enumMember', $parsedFile, ['declaration']);
        } elseif ($node instanceof Stmt\ClassConst) {
            foreach ($node->consts as $constant) {
                $this->addNode($constant->name, 'enumMember', $parsedFile, ['declaration', 'static']);
            }
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $this->addIdentifiers($this->spans->resolve($parsedFile, $node->name), 'function');
        } elseif (
            ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
        ) {
            $this->addNode($node->name, 'method', $parsedFile);
        } elseif ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $this->addNode($node->name, 'method', $parsedFile, ['static']);
            $this->addClassReference($node->class, $parsedFile);
        } elseif (
            ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)
            && $node->name instanceof Node\Identifier
        ) {
            $this->addNode($node->name, 'property', $parsedFile);
        } elseif ($node instanceof Expr\StaticPropertyFetch) {
            if ($node->name instanceof Node\VarLikeIdentifier) {
                $this->addNode($node->name, 'property', $parsedFile, ['static']);
            }

            $this->addClassReference($node->class, $parsedFile);
        } elseif ($node instanceof Expr\ClassConstFetch) {
            if ($node->name instanceof Node\Identifier) {
                $this->addNode($node->name, 'enumMember', $parsedFile, ['static']);
            }

            $this->addClassReference($node->class, $parsedFile);
        } elseif ($node instanceof Expr\New_) {
            $this->addClassReference($node->class, $parsedFile);
        } elseif ($node instanceof Expr\Instanceof_) {
            $this->addClassReference($node->class, $parsedFile);
        } elseif ($node instanceof Expr\ConstFetch && $this->isPredefinedConstant($node->name)) {
            $this->addIdentifiers(
                $this->spans->resolve($parsedFile, $node->name),
                'enumMember',
                ['defaultLibrary'],
            );
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addType($type, $parsedFile, 'class');
            }
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addType($trait, $parsedFile, 'class');
            }
        } elseif ($node instanceof Node\Attribute) {
            $this->addType($node->name, $parsedFile, 'decorator');
        } elseif ($node instanceof Node\Arg && $node->name !== null) {
            $this->addNode($node->name, 'parameter', $parsedFile);
        } elseif ($node instanceof Expr\Variable && is_string($node->name)) {
            $this->addNode($node, 'variable', $parsedFile);
        }

        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                $role = match ($type) {
                    Stmt\Use_::TYPE_FUNCTION => 'function',
                    Stmt\Use_::TYPE_CONSTANT => 'enumMember',
                    default => 'class',
                };
                $this->addIdentifiers($this->spans->resolve($parsedFile, $use->name), $role);

                if ($use->alias !== null) {
                    $this->addNode($use->alias, $role, $parsedFile, ['declaration']);
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->visit($value, $parsedFile);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->visit($child, $parsedFile);
                    }
                }
            }
        }
    }

    private function addFunctionLikeTypes(Node\FunctionLike $node, ParsedFile $parsedFile): void
    {
        foreach ($node->getParams() as $parameter) {
            $this->addType($parameter->type, $parsedFile);
        }

        $this->addType($node->getReturnType(), $parsedFile);
    }

    private function addClassReference(Node $node, ParsedFile $parsedFile): void
    {
        if ($node instanceof Name) {
            $this->addType($node, $parsedFile, 'class');
        }
    }

    private function addType(?Node $node, ParsedFile $parsedFile, string $role = 'type'): void
    {
        if ($node === null) {
            return;
        }

        if ($node instanceof Name) {
            $this->addIdentifiers($this->spans->resolve($parsedFile, $node), $role);

            return;
        }

        if ($node instanceof Node\Identifier) {
            if ($this->isNativeType($node->name, $role)) {
                $this->addNode($node, 'type', $parsedFile, ['defaultLibrary']);

                return;
            }

            $this->addNode($node, $role, $parsedFile);

            return;
        }

        if (
            $node instanceof Node\NullableType
            || $node instanceof Node\UnionType
            || $node instanceof Node\IntersectionType
        ) {
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if ($value instanceof Node) {
                    $this->addType($value, $parsedFile, $role);
                } elseif (is_array($value)) {
                    foreach ($value as $type) {
                        if ($type instanceof Node) {
                            $this->addType($type, $parsedFile, $role);
                        }
                    }
                }
            }
        }
    }

    /** @param list<string> $modifiers */
    private function addDeclarationName(
        ?Node\Identifier $name,
        string $role,
        ParsedFile $parsedFile,
        array $modifiers,
    ): void {
        if ($name !== null) {
            $this->addNode($name, $role, $parsedFile, $modifiers);
        }
    }

    /** @param list<string> $modifiers */
    private function addNode(
        Node $node,
        string $role,
        ParsedFile $parsedFile,
        array $modifiers = [],
    ): void {
        $this->add($this->spans->resolve($parsedFile, $node), $role, $modifiers);
    }

    private function addExtensionSyntax(ParsedFile $parsedFile): void
    {
        foreach ($parsedFile->extensionSyntax->typedLocals as $declaration) {
            $this->addSourceType($declaration->type);
            $this->add($declaration->variableSpan, 'variable', ['declaration']);

            if ($declaration->readonlySpan !== null) {
                $this->add($declaration->readonlySpan, 'keyword');
            }
        }

        foreach ($parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $this->addSourceType($declaration->type);
            $this->add($declaration->variableSpan, 'variable', ['declaration']);

            if ($declaration->readonlySpan !== null) {
                $this->add($declaration->readonlySpan, 'keyword');
            }
        }

        foreach ($parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->addSourceType($binding->type);
            $this->add($binding->variableSpan, 'variable', ['declaration']);
        }

        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            foreach ($declaration->parameters as $parameter) {
                $this->add($parameter->nameSpan, 'typeParameter', ['declaration']);

                if ($parameter->bound !== null) {
                    $this->addSourceType($parameter->bound);
                }
            }
        }

        foreach ($parsedFile->extensionSyntax->genericTypes as $type) {
            $this->addIdentifiers($type->nameSpan, 'type');

            foreach ($type->arguments as $argument) {
                $this->addSourceType($argument);
            }
        }

        foreach ($parsedFile->extensionSyntax->throwsClauses as $clause) {
            $this->add($clause->keywordSpan, 'keyword');

            foreach ($clause->errorTypes as $type) {
                $this->addSourceType($type);
            }
        }

        foreach ($parsedFile->extensionSyntax->whenExpressions as $expression) {
            foreach ($expression->branches as $branch) {
                $this->add($branch->whenKeywordSpan, 'keyword');

                if ($branch->elseKeywordSpan !== null) {
                    $this->add($branch->elseKeywordSpan, 'keyword');
                }
            }

            $this->add($expression->elseBranch->elseKeywordSpan, 'keyword');
        }
    }

    private function addSourceType(SourceType $type): void
    {
        $this->addIdentifiers($type->span, 'type');
    }

    /** @param list<string> $modifiers */
    private function addIdentifiers(Span $span, string $role, array $modifiers = []): void
    {
        preg_match_all(
            '/[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/',
            $span->text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$text, $offset]) {
            $start = $span->start->offset + $offset;

            if ($this->isNativeType($text, $role)) {
                $this->add(
                    $span->sourceFile->createSpan($start, $start + strlen($text)),
                    'type',
                    ['defaultLibrary'],
                );

                continue;
            }

            $this->add(
                $span->sourceFile->createSpan($start, $start + strlen($text)),
                $role,
                $modifiers,
            );
        }
    }

    private function isNativeType(string $text, string $role): bool
    {
        return in_array($role, ['type', 'class'], true)
            && in_array(strtolower($text), self::PHP_NATIVE_TYPES, true);
    }

    private function isPredefinedConstant(Name $name): bool
    {
        return in_array(strtolower($name->toString()), ['false', 'null', 'true'], true);
    }

    /** @param list<string> $modifiers */
    private function add(Span $span, string $role, array $modifiers = []): void
    {
        if ($span->isEmpty) {
            return;
        }

        $priority = match ($role) {
            'method', 'property', 'function', 'parameter', 'typeParameter' => 30,
            'class', 'enum', 'interface', 'namespace', 'enumMember', 'decorator' => 20,
            'variable' => 10,
            default => 0,
        };
        $key = sprintf('%d:%d', $span->start->offset, $span->end->offset);
        $existing = $this->tokens[$key] ?? null;

        if ($existing === null || $priority > $existing['priority']) {
            $this->tokens[$key] = [
                'priority' => $priority,
                'token' => new EditorSemanticToken($role, $span, array_values(array_unique($modifiers))),
            ];
        }
    }
}
