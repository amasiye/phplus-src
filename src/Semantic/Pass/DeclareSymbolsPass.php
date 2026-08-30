<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\PhpDoc\PhpDocReader;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Semantic\ProjectSemanticContext;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Type\TypeResolver;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeParser;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final readonly class DeclareSymbolsPass
{
    public function __construct(
        private TypeResolver $types = new TypeResolver(),
        private NodeSpanResolver $spans = new NodeSpanResolver(),
        private PhpDocReader $phpDoc = new PhpDocReader(),
        private CompositeTypeParser $compositeTypes = new CompositeTypeParser(),
    ) {}

    public function execute(ProjectSemanticContext $context): void
    {
        foreach ($context->parseResult->parsedFiles as $parsedFile) {
            $this->collectStatements($parsedFile->statements, $parsedFile, $context, '');
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
                $context->symbols->declareFunction(new FunctionSymbol(
                    $name,
                    $namespace,
                    $this->parameters(array_values($statement->params), $parsedFile, $context, $statement->getDocComment()),
                    $this->resolveType($statement->returnType, $context),
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
            $parent = $statement instanceof Stmt\Class_ && $statement->extends !== null
                ? $this->resolveName($statement->extends, $context)
                : null;
            $interfaces = $statement instanceof Stmt\Class_
                ? array_values(array_map(fn (Node\Name $interface): string => $this->resolveName($interface, $context), $statement->implements))
                : ($statement instanceof Stmt\Interface_
                    ? array_values(array_map(fn (Node\Name $interface): string => $this->resolveName($interface, $context), $statement->extends))
                    : []);
            $traits = [];

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\TraitUse) {
                    array_push($traits, ...array_map(fn (Node\Name $trait): string => $this->resolveName($trait, $context), $member->traits));
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
            );

            foreach ($statement->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod) {
                    $class->declareMethod(new MethodSymbol(
                        $name,
                        $member->name->toString(),
                        $this->parameters(array_values($member->params), $parsedFile, $context, $member->getDocComment()),
                        $this->resolveType($member->returnType, $context),
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
                                $this->resolveType($parameter->type, $context),
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
                            $this->resolveType($member->type, $context),
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
     * @return list<ParameterSymbol>
     */
    private function parameters(
        array $parameters,
        ParsedFile $parsedFile,
        ProjectSemanticContext $context,
        ?Doc $document = null,
    ): array
    {
        $documentedParameters = $this->phpDoc->readMetadata($document)->parameters;

        return array_map(function (Node\Param $parameter) use (
            $parsedFile,
            $context,
            $documentedParameters,
        ): ParameterSymbol {
            $name = $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : '$unknown';
            $documented = $documentedParameters[$name] ?? null;

            return new ParameterSymbol(
                $name,
                $this->resolveType($parameter->type, $context),
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

    private function resolveType(?Node $type, ProjectSemanticContext $context): ?\Amasiye\Ppphp\Semantic\Type\NamedType
    {
        return $this->types->resolve(
            $type,
            fn (Node\Name $name): string => $this->resolveName($name, $context),
        );
    }

    private function resolveName(Node\Name $name, ProjectSemanticContext $context): string
    {
        return $context->resolvedNames->resolve($name) ?? $name->toString();
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
