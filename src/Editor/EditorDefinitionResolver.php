<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Frontend\Ast\SourceType;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticModel;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\TypeName;
use Amasiye\Ppphp\Source\Span;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final readonly class EditorDefinitionResolver
{
    public function __construct(private NodeSpanResolver $spans = new NodeSpanResolver()) {}

    public function resolve(
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?EditorDefinition {
        if ($offset < 0 || $offset > $parsedFile->sourceFile->length) {
            throw new \OutOfBoundsException('The editor definition offset is outside the parsed file.');
        }

        $declared = $this->resolveDeclaredSymbol($parsedFile, $analysis->symbols, $offset);

        if ($declared !== null) {
            return $declared;
        }

        $local = $this->resolveLocalBinding($analysis->findModel($parsedFile->sourceFile->path), $offset);

        if ($local !== null) {
            return $local;
        }

        $extensionType = $this->resolveExtensionType($parsedFile, $analysis, $offset);

        if ($extensionType !== null) {
            return $extensionType;
        }

        foreach ($parsedFile->statements as $statement) {
            $definition = $this->resolveNode(
                $statement,
                null,
                [],
                $parsedFile,
                $analysis,
                $offset,
            );

            if ($definition !== null) {
                return $definition;
            }
        }

        return null;
    }

    private function resolveDeclaredSymbol(
        ParsedFile $parsedFile,
        SymbolTable $symbols,
        int $offset,
    ): ?EditorDefinition {
        foreach ($symbols->classes as $class) {
            if ($this->containsOffset($class->selectionSpan, $parsedFile, $offset)) {
                return $this->fromClass($class);
            }

            foreach ($class->methods as $method) {
                if ($this->containsOffset($method->selectionSpan, $parsedFile, $offset)) {
                    return $this->fromMethod($method);
                }

                foreach ($method->parameters as $parameter) {
                    if ($this->containsOffset($parameter->selectionSpan, $parsedFile, $offset)) {
                        return $this->fromParameter($parameter, $this->methodId($method));
                    }
                }
            }

            foreach ($class->properties as $property) {
                if ($this->containsOffset($property->selectionSpan, $parsedFile, $offset)) {
                    return $this->fromProperty($class, $property);
                }
            }
        }

        foreach ($symbols->functions as $function) {
            if ($this->containsOffset($function->selectionSpan, $parsedFile, $offset)) {
                return $this->fromFunction($function);
            }

            foreach ($function->parameters as $parameter) {
                if ($this->containsOffset($parameter->selectionSpan, $parsedFile, $offset)) {
                    return $this->fromParameter($parameter, $this->functionId($function));
                }
            }
        }

        return null;
    }

    private function resolveLocalBinding(?SemanticModel $model, int $offset): ?EditorDefinition
    {
        if ($model === null) {
            return null;
        }

        foreach ($model->bindings->bindings as $binding) {
            foreach ([$binding->variableSpan, ...$binding->reads, ...$binding->writes] as $span) {
                if ($this->spanContainsOffset($span, $offset)) {
                    return new EditorDefinition(
                        sprintf(
                            'local:%s:%d:%s',
                            Path::buildComparisonKey($binding->variableSpan->sourceFile->displayPath),
                            $binding->variableSpan->start->offset,
                            $binding->name,
                        ),
                        'variable',
                        $binding->declarationSpan,
                        $binding->variableSpan,
                    );
                }
            }
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveNode(
        Node $node,
        ?Node $parent,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?EditorDefinition {
        $span = $this->spans->resolve($parsedFile, $node);

        if (!$this->spanContainsOffset($span, $offset)) {
            return null;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $definition = $this->resolveNode(
                    $value,
                    $node,
                    [...$ancestors, $node],
                    $parsedFile,
                    $analysis,
                    $offset,
                );

                if ($definition !== null) {
                    return $definition;
                }
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if (!$child instanceof Node) {
                        continue;
                    }

                    $definition = $this->resolveNode(
                        $child,
                        $node,
                        [...$ancestors, $node],
                        $parsedFile,
                        $analysis,
                        $offset,
                    );

                    if ($definition !== null) {
                        return $definition;
                    }
                }
            }
        }

        if ($node instanceof Name) {
            return $this->resolveName($node, $parent, $ancestors, $parsedFile, $analysis);
        }

        if ($node instanceof Node\Identifier) {
            return $this->resolveIdentifier($node, $parent, $ancestors, $parsedFile, $analysis);
        }

        if ($node instanceof Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                $class = $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);

                return $class === null ? null : $this->fromClass($class);
            }

            return $this->resolveParameterReference($node, $ancestors, $parsedFile, $analysis);
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveName(
        Name $name,
        ?Node $parent,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?EditorDefinition {
        if ($parent instanceof Node\UseItem && $parent->name === $name) {
            return $this->resolveImportedName($name, $parent, $ancestors, $analysis);
        }

        if ($parent instanceof Expr\FuncCall && $parent->name === $name) {
            $resolved = $analysis->resolvedNames->resolve($name) ?? $name->toString();
            $function = $analysis->symbols->findFunction($resolved);

            if ($function === null && !str_contains($name->toString(), '\\')) {
                $function = $analysis->symbols->findFunction($name->toString());
            }

            return $function === null ? null : $this->fromFunction($function);
        }

        if ($parent instanceof Stmt\Namespace_) {
            return null;
        }

        $useType = $this->resolveUseType($ancestors);

        if ($useType === Stmt\Use_::TYPE_FUNCTION || $useType === Stmt\Use_::TYPE_CONSTANT) {
            return null;
        }

        $class = $this->resolveClassFromName($name, $ancestors, $parsedFile, $analysis);

        return $class === null ? null : $this->fromClass($class);
    }

    /** @param list<Node> $ancestors */
    private function resolveImportedName(
        Name $name,
        Node\UseItem $item,
        array $ancestors,
        SemanticAnalysisResult $analysis,
    ): ?EditorDefinition {
        $type = $item->type;
        $qualifiedName = $name->toString();

        foreach (array_reverse($ancestors) as $ancestor) {
            if ($ancestor instanceof Stmt\GroupUse) {
                $qualifiedName = $ancestor->prefix->toString() . '\\' . $qualifiedName;

                if ($type === Stmt\Use_::TYPE_UNKNOWN) {
                    $type = $ancestor->type;
                }

                break;
            }

            if ($ancestor instanceof Stmt\Use_) {
                if ($type === Stmt\Use_::TYPE_UNKNOWN) {
                    $type = $ancestor->type;
                }

                break;
            }
        }

        if ($type === Stmt\Use_::TYPE_FUNCTION) {
            $function = $analysis->symbols->findFunction($qualifiedName);

            return $function === null ? null : $this->fromFunction($function);
        }

        if ($type === Stmt\Use_::TYPE_NORMAL) {
            $class = $analysis->symbols->findClass($qualifiedName);

            return $class === null ? null : $this->fromClass($class);
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveIdentifier(
        Node\Identifier|Node\VarLikeIdentifier $identifier,
        ?Node $parent,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?EditorDefinition {
        $name = $identifier->toString();

        if (
            ($parent instanceof Expr\MethodCall || $parent instanceof Expr\NullsafeMethodCall)
            && $parent->name === $identifier
        ) {
            $receiver = $this->resolveReceiverType($parent->var, $ancestors, $parsedFile, $analysis);
            $method = $receiver === null
                ? null
                : $this->findMethod($receiver->class, $name, $analysis->symbols);

            return $method === null ? null : $this->fromMethod($method);
        }

        if ($parent instanceof Expr\StaticCall && $parent->name === $identifier) {
            $class = $parent->class instanceof Name
                ? $this->resolveClassFromName($parent->class, $ancestors, $parsedFile, $analysis)
                : null;
            $method = $class === null
                ? null
                : $this->findMethod($class, $name, $analysis->symbols);

            return $method === null ? null : $this->fromMethod($method);
        }

        if (
            ($parent instanceof Expr\PropertyFetch || $parent instanceof Expr\NullsafePropertyFetch)
            && $parent->name === $identifier
        ) {
            $receiver = $this->resolveReceiverType($parent->var, $ancestors, $parsedFile, $analysis);
            $property = $receiver === null
                ? null
                : $this->findProperty($receiver->class, $name, $analysis->symbols);

            return $property === null ? null : $this->fromProperty($property[0], $property[1]);
        }

        if ($parent instanceof Expr\StaticPropertyFetch && $parent->name === $identifier) {
            $class = $parent->class instanceof Name
                ? $this->resolveClassFromName($parent->class, $ancestors, $parsedFile, $analysis)
                : null;
            $property = $class === null
                ? null
                : $this->findProperty($class, ltrim($name, '$'), $analysis->symbols);

            return $property === null ? null : $this->fromProperty($property[0], $property[1]);
        }

        if (
            $parent instanceof Expr\ClassConstFetch
            && $parent->name === $identifier
            && strtolower($name) === 'class'
            && $parent->class instanceof Name
        ) {
            $class = $this->resolveClassFromName($parent->class, $ancestors, $parsedFile, $analysis);

            return $class === null ? null : $this->fromClass($class);
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveReceiverType(
        Expr $receiver,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?EditorReceiverType {
        if ($receiver instanceof Expr\Variable && $receiver->name === 'this') {
            $class = $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);

            return $class === null ? null : new EditorReceiverType($class);
        }

        $receiverName = $receiver instanceof Expr\Variable ? $receiver->name : null;

        if ($receiver instanceof Expr\Variable && is_string($receiverName)) {
            $binding = $this->findBindingForSpan(
                $analysis->findModel($parsedFile->sourceFile->path),
                $this->spans->resolve($parsedFile, $receiver),
            );

            if ($binding !== null) {
                return $this->resolveReceiverFromSemanticType(
                    $binding->type->semanticType,
                    $ancestors,
                    $parsedFile,
                    $analysis,
                    $binding->variableSpan->start->offset,
                );
            }

            $parameter = $this->findParameterReference($receiverName, $ancestors, $parsedFile, $analysis);

            return $parameter === null
                ? null
                : $this->resolveReceiverFromNamedType(
                    $parameter->type,
                    [],
                    $ancestors,
                    $parsedFile,
                    $analysis,
                    $parameter->selectionSpan->start->offset,
                );
        }

        if ($receiver instanceof Expr\New_ && $receiver->class instanceof Name) {
            $class = $this->resolveClassFromName($receiver->class, $ancestors, $parsedFile, $analysis);

            return $class === null ? null : new EditorReceiverType($class);
        }

        if ($receiver instanceof Expr\FuncCall && $receiver->name instanceof Name) {
            $resolved = $analysis->resolvedNames->resolve($receiver->name) ?? $receiver->name->toString();
            $function = $analysis->symbols->findFunction($resolved);

            if ($function === null && !str_contains($receiver->name->toString(), '\\')) {
                $function = $analysis->symbols->findFunction($receiver->name->toString());
            }

            return $function === null ? null : $this->resolveReceiverFromNamedType(
                    $function->returnType,
                    [],
                    $ancestors,
                    $parsedFile,
                    $analysis,
                    $this->spans->resolve($parsedFile, $receiver)->start->offset,
                );
        }

        if ($receiver instanceof Expr\MethodCall || $receiver instanceof Expr\NullsafeMethodCall) {
            if (!$receiver->name instanceof Node\Identifier) {
                return null;
            }

            $owner = $this->resolveReceiverType($receiver->var, $ancestors, $parsedFile, $analysis);
            $method = $owner === null
                ? null
                : $this->findMethod($owner->class, $receiver->name->toString(), $analysis->symbols);

            return $method === null
                ? null
                : $this->resolveReceiverFromNamedType(
                    $method->returnType,
                    $owner->argumentsByParameter,
                    $ancestors,
                    $parsedFile,
                    $analysis,
                    $this->spans->resolve($parsedFile, $receiver)->start->offset,
                    $analysis->symbols->findClass($method->owner),
                );
        }

        if ($receiver instanceof Expr\PropertyFetch || $receiver instanceof Expr\NullsafePropertyFetch) {
            if (!$receiver->name instanceof Node\Identifier) {
                return null;
            }

            $owner = $this->resolveReceiverType($receiver->var, $ancestors, $parsedFile, $analysis);
            $property = $owner === null
                ? null
                : $this->findProperty($owner->class, $receiver->name->toString(), $analysis->symbols);

            return $property === null
                ? null
                : $this->resolveReceiverFromNamedType(
                    $property[1]->type,
                    $owner->argumentsByParameter,
                    $ancestors,
                    $parsedFile,
                    $analysis,
                    $this->spans->resolve($parsedFile, $receiver)->start->offset,
                    $property[0],
                );
        }

        return null;
    }

    /**
     * @param array<string, Type> $argumentsByParameter
     * @param list<Node> $ancestors
     */
    private function resolveReceiverFromNamedType(
        ?NamedType $type,
        array $argumentsByParameter,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
        ?ClassSymbol $declarationOwner = null,
    ): ?EditorReceiverType {
        if ($type === null) {
            return null;
        }

        $substitution = $argumentsByParameter[strtolower($type->text)]
            ?? $argumentsByParameter[strtolower(TypeName::resolveShort($type->text))]
            ?? null;

        if ($substitution !== null) {
            return $this->resolveReceiverFromSemanticType(
                $substitution,
                $ancestors,
                $parsedFile,
                $analysis,
                $offset,
            );
        }

        $class = $this->resolveClassFromNamedType(
            $type,
            $ancestors,
            $parsedFile,
            $analysis,
            $offset,
            $declarationOwner,
        );

        return $class === null ? null : new EditorReceiverType($class);
    }

    /** @param list<Node> $ancestors */
    private function resolveReceiverFromSemanticType(
        Type $type,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?EditorReceiverType {
        $atomic = $type instanceof GenericType ? $type->base : $type;

        if (!$atomic instanceof AtomicType || $atomic->isBuiltin) {
            return null;
        }

        $class = $this->resolveClassFromSourceName(
            $atomic->name,
            $ancestors,
            $parsedFile,
            $analysis,
            $offset,
        );

        if ($class === null || !$type instanceof GenericType) {
            return $class === null ? null : new EditorReceiverType($class);
        }

        $arguments = [];
        $parameters = $class->genericDeclaration->parameters ?? [];

        foreach ($parameters as $index => $parameter) {
            if (isset($type->arguments[$index])) {
                $arguments[strtolower($parameter->name)] = $type->arguments[$index];
            }
        }

        return new EditorReceiverType($class, $arguments);
    }

    private function findBindingForSpan(?SemanticModel $model, Span $span): ?\Amasiye\Ppphp\Semantic\Binding\LocalBinding
    {
        if ($model === null) {
            return null;
        }

        foreach ($model->bindings->bindings as $binding) {
            foreach ([$binding->variableSpan, ...$binding->reads, ...$binding->writes] as $candidate) {
                if ($this->sameSpan($candidate, $span)) {
                    return $binding;
                }
            }
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveParameterReference(
        Expr\Variable $variable,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?EditorDefinition {
        $variableName = $variable->name;

        if (!is_string($variableName)) {
            return null;
        }

        $parameter = $this->findParameterReference($variableName, $ancestors, $parsedFile, $analysis);

        if ($parameter !== null) {
            $callableId = $this->resolveContainingCallableId($ancestors, $parsedFile, $analysis->symbols);

            return $this->fromParameter($parameter, $callableId ?? 'callable:unknown');
        }

        foreach (array_reverse($ancestors) as $ancestor) {
            if (!$ancestor instanceof Expr\Closure && !$ancestor instanceof Expr\ArrowFunction) {
                continue;
            }

            foreach ($ancestor->getParams() as $candidate) {
                if (
                    $candidate->var instanceof Expr\Variable
                    && $candidate->var->name === $variableName
                ) {
                    $declaration = $this->spans->resolve($parsedFile, $candidate);
                    $selection = $this->spans->resolve($parsedFile, $candidate->var);

                    return new EditorDefinition(
                        sprintf('parameter:%s:%d:$%s', $parsedFile->sourceFile->displayPath, $selection->start->offset, $variableName),
                        'parameter',
                        $declaration,
                        $selection,
                    );
                }
            }

            break;
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function findParameterReference(
        string $name,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?ParameterSymbol {
        foreach (array_reverse($ancestors) as $ancestor) {
            if ($ancestor instanceof Stmt\ClassMethod) {
                $class = $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);
                $method = $class?->findMethod($ancestor->name->toString());

                return $method === null
                    ? null
                    : $this->findParameter($method->parameters, '$' . $name);
            }

            if ($ancestor instanceof Stmt\Function_) {
                $function = $this->findFunctionByDeclaration($ancestor, $parsedFile, $analysis->symbols);

                return $function === null
                    ? null
                    : $this->findParameter($function->parameters, '$' . $name);
            }

            if ($ancestor instanceof FunctionLike) {
                return null;
            }
        }

        return null;
    }

    /** @param list<ParameterSymbol> $parameters */
    private function findParameter(array $parameters, string $name): ?ParameterSymbol
    {
        foreach ($parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        return null;
    }

    private function resolveExtensionType(
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?EditorDefinition {
        $types = [];

        foreach ($parsedFile->extensionSyntax->typedLocals as $declaration) {
            $types[] = $declaration->type;
        }

        foreach ($parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $types[] = $declaration->type;
        }

        foreach ($parsedFile->extensionSyntax->typedForeachBindings as $declaration) {
            $types[] = $declaration->type;
        }

        foreach ($parsedFile->extensionSyntax->throwsClauses as $clause) {
            array_push($types, ...$clause->errorTypes);
        }

        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            foreach ($declaration->parameters as $parameter) {
                if ($parameter->bound !== null) {
                    $types[] = $parameter->bound;
                }
            }
        }

        foreach ($types as $type) {
            $definition = $this->resolveSourceType($type, $parsedFile, $analysis, $offset);

            if ($definition !== null) {
                return $definition;
            }
        }

        return null;
    }

    private function resolveSourceType(
        SourceType $type,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?EditorDefinition {
        if (!$this->spanContainsOffset($type->span, $offset)) {
            return null;
        }

        foreach ($type->genericReferences as $generic) {
            foreach ($generic->arguments as $argument) {
                $definition = $this->resolveSourceType($argument, $parsedFile, $analysis, $offset);

                if ($definition !== null) {
                    return $definition;
                }
            }
        }

        $name = $this->readQualifiedNameAt($type->span->sourceFile->contents, $offset);

        if ($name === null) {
            return null;
        }

        $class = $this->resolveClassFromSourceName($name, [], $parsedFile, $analysis, $offset);

        return $class === null ? null : $this->fromClass($class);
    }

    /** @param list<Node> $ancestors */
    private function resolveClassFromName(
        Name $name,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
    ): ?ClassSymbol {
        $text = strtolower($name->toString());

        if (in_array($text, ['self', 'static'], true)) {
            return $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);
        }

        if ($text === 'parent') {
            $class = $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);

            return $class?->parent === null ? null : $analysis->symbols->findClass($class->parent);
        }

        $resolved = $analysis->resolvedNames->resolve($name);

        if ($resolved !== null) {
            $class = $analysis->symbols->findClass($resolved);

            if ($class !== null) {
                return $class;
            }
        }

        $offset = $this->spans->resolve($parsedFile, $name)->start->offset;

        return $this->resolveClassFromSourceName($name->toString(), $ancestors, $parsedFile, $analysis, $offset);
    }

    /** @param list<Node> $ancestors */
    private function resolveClassFromNamedType(
        ?NamedType $type,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
        ?ClassSymbol $declarationOwner = null,
    ): ?ClassSymbol {
        $name = $type?->resolveSingleNamedType();

        if ($name !== null && $declarationOwner !== null) {
            $lower = strtolower($name);

            if (in_array($lower, ['self', 'static'], true)) {
                return $declarationOwner;
            }

            if ($lower === 'parent') {
                return $declarationOwner->parent === null
                    ? null
                    : $analysis->symbols->findClass($declarationOwner->parent);
            }
        }

        return $name === null
            ? null
            : $this->resolveClassFromSourceName($name, $ancestors, $parsedFile, $analysis, $offset);
    }

    /** @param list<Node> $ancestors */
    private function resolveClassFromSourceName(
        string $name,
        array $ancestors,
        ParsedFile $parsedFile,
        SemanticAnalysisResult $analysis,
        int $offset,
    ): ?ClassSymbol {
        $fullyQualified = str_starts_with($name, '\\');
        $name = ltrim($name, '\\');

        if ($fullyQualified) {
            return $analysis->symbols->findClass($name);
        }

        $lower = strtolower($name);

        if (in_array($lower, ['self', 'static'], true)) {
            return $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);
        }

        if ($lower === 'parent') {
            $class = $this->resolveContainingClass($ancestors, $parsedFile, $analysis->symbols);

            return $class?->parent === null ? null : $analysis->symbols->findClass($class->parent);
        }

        [$namespace, $aliases] = $this->resolveNameContext($parsedFile, $offset);
        $segments = explode('\\', $name);
        $alias = strtolower($segments[0]);

        if (isset($aliases[$alias])) {
            array_shift($segments);
            $qualified = $aliases[$alias] . ($segments === [] ? '' : '\\' . implode('\\', $segments));

            return $analysis->symbols->findClass($qualified);
        }

        if ($namespace !== '') {
            $class = $analysis->symbols->findClass($namespace . '\\' . $name);

            if ($class !== null) {
                return $class;
            }
        }

        return $analysis->symbols->findClass($name);
    }

    /** @return array{string, array<string, string>} */
    private function resolveNameContext(ParsedFile $parsedFile, int $offset): array
    {
        $namespace = '';
        $statements = $parsedFile->statements;

        foreach ($parsedFile->statements as $statement) {
            if (!$statement instanceof Stmt\Namespace_) {
                continue;
            }

            $span = $this->spans->resolve($parsedFile, $statement);

            if ($this->spanContainsOffset($span, $offset)) {
                $namespace = $statement->name?->toString() ?? '';
                $statements = array_values($statement->stmts);
                break;
            }
        }

        $aliases = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Use_) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;

                    if ($type === Stmt\Use_::TYPE_NORMAL || $type === Stmt\Use_::TYPE_UNKNOWN) {
                        $aliases[strtolower($use->getAlias()->toString())] = $use->name->toString();
                    }
                }
            } elseif ($statement instanceof Stmt\GroupUse) {
                foreach ($statement->uses as $use) {
                    $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;

                    if ($type === Stmt\Use_::TYPE_NORMAL || $type === Stmt\Use_::TYPE_UNKNOWN) {
                        $aliases[strtolower($use->getAlias()->toString())] = $statement->prefix->toString() . '\\' . $use->name->toString();
                    }
                }
            }
        }

        return [$namespace, $aliases];
    }

    /** @param list<Node> $ancestors */
    private function resolveContainingClass(
        array $ancestors,
        ParsedFile $parsedFile,
        SymbolTable $symbols,
    ): ?ClassSymbol {
        foreach (array_reverse($ancestors) as $ancestor) {
            if (!$ancestor instanceof Stmt\ClassLike || $ancestor->name === null) {
                continue;
            }

            $span = $this->spans->resolve($parsedFile, $ancestor);

            foreach ($symbols->classes as $class) {
                if ($this->sameSpan($class->declarationSpan, $span)) {
                    return $class;
                }
            }
        }

        return null;
    }

    private function findFunctionByDeclaration(
        Stmt\Function_ $node,
        ParsedFile $parsedFile,
        SymbolTable $symbols,
    ): ?FunctionSymbol {
        $span = $this->spans->resolve($parsedFile, $node);

        foreach ($symbols->functions as $function) {
            if ($this->sameSpan($function->declarationSpan, $span)) {
                return $function;
            }
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveContainingCallableId(
        array $ancestors,
        ParsedFile $parsedFile,
        SymbolTable $symbols,
    ): ?string {
        foreach (array_reverse($ancestors) as $ancestor) {
            if ($ancestor instanceof Stmt\ClassMethod) {
                $class = $this->resolveContainingClass($ancestors, $parsedFile, $symbols);
                $method = $class?->findMethod($ancestor->name->toString());

                return $method === null ? null : $this->methodId($method);
            }

            if ($ancestor instanceof Stmt\Function_) {
                $function = $this->findFunctionByDeclaration($ancestor, $parsedFile, $symbols);

                return $function === null ? null : $this->functionId($function);
            }
        }

        return null;
    }

    /** @param array<string, true> $visited */
    private function findMethod(
        ClassSymbol $class,
        string $name,
        SymbolTable $symbols,
        array &$visited = [],
    ): ?MethodSymbol {
        $key = strtolower($class->fullyQualifiedName);

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $method = $class->findMethod($name);

        if ($method !== null) {
            return $method;
        }

        foreach ($class->traits as $traitName) {
            $trait = $symbols->findClass($traitName);
            $method = $trait === null ? null : $this->findMethod($trait, $name, $symbols, $visited);

            if ($method !== null) {
                return $method;
            }
        }

        if ($class->parent !== null) {
            $parent = $symbols->findClass($class->parent);
            $method = $parent === null ? null : $this->findMethod($parent, $name, $symbols, $visited);

            if ($method !== null) {
                return $method;
            }
        }

        foreach ($class->interfaces as $interfaceName) {
            $interface = $symbols->findClass($interfaceName);
            $method = $interface === null ? null : $this->findMethod($interface, $name, $symbols, $visited);

            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $visited
     * @return array{ClassSymbol, PropertySymbol}|null
     */
    private function findProperty(
        ClassSymbol $class,
        string $name,
        SymbolTable $symbols,
        array &$visited = [],
    ): ?array {
        $key = strtolower($class->fullyQualifiedName);

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $property = $class->findProperty($name);

        if ($property !== null) {
            return [$class, $property];
        }

        foreach ($class->traits as $traitName) {
            $trait = $symbols->findClass($traitName);
            $property = $trait === null ? null : $this->findProperty($trait, $name, $symbols, $visited);

            if ($property !== null) {
                return $property;
            }
        }

        if ($class->parent !== null) {
            $parent = $symbols->findClass($class->parent);

            return $parent === null ? null : $this->findProperty($parent, $name, $symbols, $visited);
        }

        return null;
    }

    /** @param list<Node> $ancestors */
    private function resolveUseType(array $ancestors): ?int
    {
        foreach (array_reverse($ancestors) as $ancestor) {
            if ($ancestor instanceof Stmt\Use_) {
                return $ancestor->type;
            }

            if ($ancestor instanceof Stmt\GroupUse) {
                return $ancestor->type;
            }
        }

        return null;
    }

    private function readQualifiedNameAt(string $source, int $offset): ?string
    {
        $isName = static fn (string $character): bool => ctype_alnum($character) || $character === '_' || $character === '\\';
        $start = min(max($offset, 0), strlen($source));

        if (($source[$start] ?? null) !== null && !$isName($source[$start]) && $start > 0 && $isName($source[$start - 1])) {
            $start--;
        }

        $end = $start;

        while ($start > 0 && $isName($source[$start - 1])) {
            $start--;
        }

        while ($end < strlen($source) && $isName($source[$end])) {
            $end++;
        }

        $name = trim(substr($source, $start, $end - $start), '\\');

        return $name === '' ? null : $name;
    }

    private function fromClass(ClassSymbol $class): EditorDefinition
    {
        return new EditorDefinition(
            'type:' . strtolower(ltrim($class->fullyQualifiedName, '\\')),
            $class->kind,
            $class->declarationSpan,
            $class->selectionSpan,
        );
    }

    private function fromFunction(FunctionSymbol $function): EditorDefinition
    {
        return new EditorDefinition(
            $this->functionId($function),
            'function',
            $function->declarationSpan,
            $function->selectionSpan,
        );
    }

    private function fromMethod(MethodSymbol $method): EditorDefinition
    {
        return new EditorDefinition(
            $this->methodId($method),
            'method',
            $method->declarationSpan,
            $method->selectionSpan,
        );
    }

    private function fromProperty(ClassSymbol $class, PropertySymbol $property): EditorDefinition
    {
        return new EditorDefinition(
            sprintf('property:%s::$%s', strtolower(ltrim($class->fullyQualifiedName, '\\')), $property->name),
            'property',
            $property->declarationSpan,
            $property->selectionSpan,
        );
    }

    private function fromParameter(ParameterSymbol $parameter, string $callableId): EditorDefinition
    {
        return new EditorDefinition(
            sprintf('parameter:%s:%s', $callableId, $parameter->name),
            'parameter',
            $parameter->declarationSpan,
            $parameter->selectionSpan,
        );
    }

    private function functionId(FunctionSymbol $function): string
    {
        return 'function:' . strtolower(ltrim($function->fullyQualifiedName, '\\'));
    }

    private function methodId(MethodSymbol $method): string
    {
        return sprintf('method:%s::%s', strtolower(ltrim($method->owner, '\\')), strtolower($method->name));
    }

    private function containsOffset(Span $span, ParsedFile $parsedFile, int $offset): bool
    {
        return Path::buildComparisonKey($span->sourceFile->path) === Path::buildComparisonKey($parsedFile->sourceFile->path)
            && $this->spanContainsOffset($span, $offset);
    }

    private function spanContainsOffset(Span $span, int $offset): bool
    {
        return $span->start->offset <= $offset && $offset < $span->end->offset;
    }

    private function sameSpan(Span $left, Span $right): bool
    {
        return Path::buildComparisonKey($left->sourceFile->path) === Path::buildComparisonKey($right->sourceFile->path)
            && $left->start->offset === $right->start->offset
            && $left->end->offset === $right->end->offset;
    }
}
