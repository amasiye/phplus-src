<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Semantic\ProjectSemanticContext;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeParser;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\Type\IntersectionType;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use Amasiye\Ppphp\Semantic\When\WhenFragmentParser;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class DeclareSymbolsPass
{
    public function __construct(
        private NodeSpanResolver $spans = new NodeSpanResolver(),
        private PhpDocReader $phpDoc = new PhpDocReader(),
        private CompositeTypeParser $compositeTypes = new CompositeTypeParser(),
        private WhenFragmentParser $whenFragments = new WhenFragmentParser(),
        private SourceNameResolver $sourceNames = new SourceNameResolver(),
    ) {}

    public function execute(ProjectSemanticContext $context): void
    {
        foreach ($context->parseResult->parsedFiles as $parsedFile) {
            $this->collectStatements($parsedFile->statements, $parsedFile, $context, '');

            foreach ($parsedFile->extensionSyntax->whenExpressions as $when) {
                $namespace = $this->sourceNames->resolveNamespaceAt(
                    $parsedFile,
                    $when->span->start->offset,
                );
                foreach ([...$when->branches, $when->elseBranch] as $branch) {
                    $fragment = $this->whenFragments->parseBody($parsedFile, $branch->bodySpan);

                    if ($fragment->isSuccessful) {
                        $this->collectStatements(
                            $fragment->statements,
                            $parsedFile,
                            $context,
                            $namespace,
                        );
                    }
                }
            }
        }
    }

    /** @param list<Stmt> $statements */
    private function collectStatements(
        array $statements,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        string $namespace,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->collectStatements(
                    array_values($statement->stmts),
                    $parsedFile,
                    $context,
                    $statement->name?->toString() ?? '',
                );
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $name = $this->qualify($namespace, $statement->name->toString());
                $typeParameters = $this->resolveGenericParameterNames($parsedFile, $statement->name);
                $context->symbols->declareFunction(new FunctionSymbol(
                    $name,
                    $namespace,
                    $this->parameters(array_values($statement->params), $parsedFile, $context, $statement->getDocComment(), $typeParameters),
                    $this->resolveType($statement->returnType, $context, $parsedFile, $typeParameters),
                    $statement->byRef,
                    $parsedFile->sourceFile,
                    $this->spans->resolve($parsedFile, $statement),
                    $this->spans->resolve($parsedFile, $statement->name),
                ));
                continue;
            }

            if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
                continue;
            }

            $name = $this->qualify($namespace, $statement->name->toString());
            $classTypeParameters = $this->resolveGenericParameterNames($parsedFile, $statement->name);
            $parentType = $statement instanceof Stmt\Class_ && $statement->extends !== null
                ? $this->resolveType($statement->extends, $context, $parsedFile, $classTypeParameters)
                : null;
            $interfaceNodes = $statement instanceof Stmt\Class_
                ? $statement->implements
                : ($statement instanceof Stmt\Interface_ ? $statement->extends : []);
            $interfaceTypes = array_values(array_filter(array_map(
                fn (Node\Name $interface): ?NamedType => $this->resolveType($interface, $context, $parsedFile, $classTypeParameters),
                $interfaceNodes,
            )));
            $parent = $parentType?->resolveSingleNamedType();
            $interfaces = array_values(array_filter(array_map(
                static fn (NamedType $interface): ?string => $interface->resolveSingleNamedType(),
                $interfaceTypes,
            )));
            $traits = [];
            $traitTypes = [];

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\TraitUse) {
                    foreach ($member->traits as $trait) {
                        $traitType = $this->resolveType($trait, $context, $parsedFile, $classTypeParameters);

                        if ($traitType === null || $traitType->resolveSingleNamedType() === null) {
                            continue;
                        }

                        $traitTypes[] = $traitType;
                        $traits[] = $traitType->resolveSingleNamedType();
                    }
                }
            }

            $class = new ClassSymbol(
                $name,
                $namespace,
                match (true) {
                    $statement instanceof Stmt\Interface_ => 'interface',
                    $statement instanceof Stmt\Trait_ => 'trait',
                    $statement instanceof Stmt\Enum_ => 'enum',
                    default => 'class',
                },
                $parsedFile->sourceFile,
                $this->spans->resolve($parsedFile, $statement),
                $this->spans->resolve($parsedFile, $statement->name),
                $parent,
                $interfaces,
                $traits,
                $parentType,
                $interfaceTypes,
                $traitTypes,
            );

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod) {
                    $methodTypeParameters = [
                        ...$classTypeParameters,
                        ...$this->resolveGenericParameterNames($parsedFile, $member->name),
                    ];
                    $class->declareMethod(new MethodSymbol(
                        $name,
                        $member->name->toString(),
                        $this->parameters(array_values($member->params), $parsedFile, $context, $member->getDocComment(), $methodTypeParameters),
                        $this->resolveType($member->returnType, $context, $parsedFile, $methodTypeParameters),
                        $this->visibility($member),
                        $member->isStatic(),
                        $member->byRef,
                        $this->spans->resolve($parsedFile, $member),
                        $this->spans->resolve($parsedFile, $member->name),
                    ));

                    if (strtolower($member->name->toString()) === '__construct') {
                        foreach ($member->params as $parameter) {
                            if (!$parameter->isPromoted() || !$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                                continue;
                            }

                            $class->declareProperty(new PropertySymbol(
                                $parameter->var->name,
                                $this->resolveType($parameter->type, $context, $parsedFile, $classTypeParameters),
                                match (true) {
                                    $parameter->isPrivate() => 'private',
                                    $parameter->isProtected() => 'protected',
                                    default => 'public',
                                },
                                false,
                                $parameter->isReadonly(),
                                $this->spans->resolve($parsedFile, $parameter),
                                $this->spans->resolve($parsedFile, $parameter->var),
                            ));
                        }
                    }
                } elseif ($member instanceof Stmt\Property) {
                    foreach ($member->props as $property) {
                        $class->declareProperty(new PropertySymbol(
                            $property->name->toString(),
                            $this->resolveType($member->type, $context, $parsedFile, $classTypeParameters),
                            $this->visibility($member),
                            $member->isStatic(),
                            $member->isReadonly(),
                            $this->spans->resolve($parsedFile, $property),
                            $this->spans->resolve($parsedFile, $property->name),
                        ));
                    }
                }
            }

            $context->symbols->declareClass($class);
        }
    }

    /**
     * @param list<Node\Param> $parameters
     * @param list<string> $typeParameters
     * @return list<ParameterSymbol>
     */
    private function parameters(
        array $parameters,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        ?Doc $document = null,
        array $typeParameters = [],
    ): array
    {
        $documentedParameters = $this->phpDoc->readMetadata($document)->parameters;

        return array_map(function (Node\Param $parameter) use (
            $parsedFile,
            $context,
            $documentedParameters,
            $typeParameters,
        ): ParameterSymbol {
            $name = $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : '$unknown';
            $documented = $documentedParameters[$name] ?? null;

            return new ParameterSymbol(
                $name,
                $this->resolveType($parameter->type, $context, $parsedFile, $typeParameters),
                $parameter->variadic,
                $parameter->byRef,
                $parameter->flags !== 0,
                $this->spans->resolve($parsedFile, $parameter),
                $this->spans->resolve($parsedFile, $parameter->var),
                $documented === null
                    ? null
                    : $this->compositeTypes->parse($this->normalizeDocumentedType($documented)),
            );
        }, $parameters);
    }

    private function normalizeDocumentedType(string $type): string
    {
        $normalized = preg_replace('/\blist\s*</i', 'array<', $type) ?? $type;

        return strcasecmp(trim($normalized), 'array-key') === 0 ? 'int|string' : $normalized;
    }

    /** @param list<string> $typeParameters */
    private function resolveType(
        ?Node $type,
        ProjectSemanticContext $context,
        ParsedFile $parsedFile,
        array $typeParameters = [],
    ): ?NamedType
    {
        if ($type === null) {
            return null;
        }

        $semanticType = $this->resolveSemanticType($type, $context, $parsedFile, $typeParameters);

        return new NamedType($semanticType->renderPhpDoc());
    }

    /** @param list<string> $typeParameters */
    private function resolveSemanticType(
        Node $type,
        ProjectSemanticContext $context,
        ParsedFile $parsedFile,
        array $typeParameters,
    ): Type {
        if ($type instanceof Node\Identifier) {
            return new AtomicType($type->toString());
        }

        if ($type instanceof Node\Name) {
            $offset = $this->spans->resolve($parsedFile, $type)->start->offset;
            $applied = $this->findAppliedType($parsedFile, $offset);

            if ($applied !== null) {
                return $this->qualifyType(
                    $this->compositeTypes->parse($applied->span->text),
                    $parsedFile,
                    $offset,
                    $typeParameters,
                );
            }

            if ($this->containsTypeParameter($typeParameters, $type->toString())) {
                return new AtomicType($type->toString());
            }

            return new AtomicType($this->resolveName($type, $context, $parsedFile));
        }

        if ($type instanceof Node\NullableType) {
            return new UnionType([
                $this->resolveSemanticType($type->type, $context, $parsedFile, $typeParameters),
                new AtomicType('null'),
            ]);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $members = array_values(array_map(
                fn (Node $member): Type => $this->resolveSemanticType($member, $context, $parsedFile, $typeParameters),
                $type->types,
            ));

            if ($members === []) {
                throw new \LogicException('A composite type must contain at least one member.');
            }

            return $type instanceof Node\UnionType
                ? new UnionType($members)
                : new IntersectionType($members);
        }

        throw new \LogicException(sprintf('Unsupported PHP type node "%s".', $type::class));
    }

    /** @param list<string> $typeParameters */
    private function qualifyType(
        Type $type,
        ParsedFile $parsedFile,
        int $offset,
        array $typeParameters,
    ): Type {
        if ($type instanceof AtomicType) {
            if ($type->isBuiltin || $this->containsTypeParameter($typeParameters, $type->name)) {
                return $type;
            }

            return new AtomicType($this->sourceNames->resolve($parsedFile, $type->name, $offset));
        }

        if ($type instanceof GenericType) {
            $base = $this->qualifyType($type->base, $parsedFile, $offset, $typeParameters);

            return new GenericType(
                $base instanceof AtomicType ? $base : $type->base,
                array_map(
                    fn (Type $argument): Type => $this->qualifyType($argument, $parsedFile, $offset, $typeParameters),
                    $type->arguments,
                ),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->qualifyType($type->keyType, $parsedFile, $offset, $typeParameters),
                $this->qualifyType($type->valueType, $parsedFile, $offset, $typeParameters),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(
                fn (Type $member): Type => $this->qualifyType($member, $parsedFile, $offset, $typeParameters),
                $type->members,
            );

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    private function findAppliedType(ParsedFile $parsedFile, int $offset): ?\Amasiye\Ppphp\Frontend\Ast\GenericType
    {
        foreach ($parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset === $offset) {
                return $reference;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function resolveGenericParameterNames(ParsedFile $parsedFile, Node\Identifier $owner): array
    {
        $offset = $this->spans->resolve($parsedFile, $owner)->start->offset;

        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($declaration->ownerNameSpan->start->offset === $offset) {
                return array_map(
                    static fn ($parameter): string => $parameter->nameSpan->text,
                    $declaration->parameters,
                );
            }
        }

        return [];
    }

    /** @param list<string> $typeParameters */
    private function containsTypeParameter(array $typeParameters, string $name): bool
    {
        foreach ($typeParameters as $parameter) {
            if (strcasecmp($parameter, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveName(Node\Name $name, ProjectSemanticContext $context, ParsedFile $parsedFile): string
    {
        $resolved = $context->resolvedNames->resolve($name);

        if ($resolved !== null) {
            return $resolved;
        }

        $offset = $name->getAttribute('ppphpOriginalStart');

        return is_int($offset)
            ? $this->sourceNames->resolve($parsedFile, $name->toString(), $offset)
            : $name->toString();
    }

    private function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function visibility(Stmt\ClassMethod|Stmt\Property $member): string
    {
        return match (true) {
            $member->isPrivate() => 'private',
            $member->isProtected() => 'protected',
            default => 'public',
        };
    }
}
