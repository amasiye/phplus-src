<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Semantic\Effect\EffectCompatibility;
use Amasiye\Ppphp\Semantic\Effect\Enumerations\ThrowableKind;
use Amasiye\Ppphp\Semantic\Effect\ErrorAnalysisScope;
use Amasiye\Ppphp\Semantic\Effect\ErrorFlow;
use Amasiye\Ppphp\Semantic\Effect\ErrorOccurrence;
use Amasiye\Ppphp\Semantic\Effect\ErrorSet;
use Amasiye\Ppphp\Semantic\Effect\ThrowableHierarchy;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\CompositeTypeParser;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\Type\IntersectionType;
use Amasiye\Ppphp\Semantic\Type\TypeParameter;
use Amasiye\Ppphp\Semantic\Type\TypeSubstitution;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use Amasiye\Ppphp\Semantic\Type\UnknownType;
use Amasiye\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class CheckErrorEffectsPass implements SemanticPass
{
    private SemanticContext $context;

    private ThrowableHierarchy $hierarchy;

    public function __construct(
        private readonly SourceNameResolver $sourceNames = new SourceNameResolver(),
        private readonly EffectCompatibility $effectCompatibility = new EffectCompatibility(),
        private readonly CompositeTypeParser $types = new CompositeTypeParser(),
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->hierarchy = new ThrowableHierarchy($context->symbols);
        $scope = new ErrorAnalysisScope('file', null, null, $this->resolveFileVariableTypes());
        $fileFlow = $this->analyzeFileStatements($context->parsedFile->statements, $scope, '');
        $this->diagnoseEscapes($fileFlow->escapingErrors, $scope);
    }

    /** @param array<Stmt> $statements */
    private function analyzeFileStatements(array $statements, ErrorAnalysisScope $scope, string $namespace): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $nested = $this->analyzeFileStatements(
                    array_values($statement->stmts),
                    $scope,
                    $statement->name?->toString() ?? '',
                );
                $flow = $flow->continueWith($nested);
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $this->analyzeFunction($statement, $namespace);
                continue;
            }

            if ($statement instanceof Stmt\ClassLike && $statement->name !== null) {
                $this->analyzeClass($statement, $namespace);
                continue;
            }

            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
                continue;
            }

            $flow = $flow->continueWith($this->analyzeNode($statement, $scope));
        }

        return $flow;
    }

    private function analyzeFunction(Stmt\Function_ $function, string $namespace): void
    {
        $name = $namespace === ''
            ? $function->name->toString()
            : $namespace . '\\' . $function->name->toString();
        $symbol = $this->context->symbols->findFunction($name);

        if ($symbol === null || $symbol->sourceFile !== $this->context->parsedFile->sourceFile) {
            return;
        }

        $scope = new ErrorAnalysisScope(
            'callable',
            $symbol->errorContract,
            null,
            $this->resolveVariableTypes($function, null),
        );
        $flow = $this->analyzeStatements($function->stmts, $scope);
        $this->diagnoseEscapes($flow->escapingErrors, $scope);
    }

    private function analyzeClass(Stmt\ClassLike $classNode, string $namespace): void
    {
        $name = $namespace === ''
            ? $classNode->name?->toString()
            : $namespace . '\\' . $classNode->name?->toString();
        $class = $name === null ? null : $this->context->symbols->findClass($name);

        if ($class === null || $class->sourceFile !== $this->context->parsedFile->sourceFile) {
            return;
        }

        foreach ($classNode->getMethods() as $methodNode) {
            $method = $class->findMethod($methodNode->name->toString());

            if ($method === null) {
                continue;
            }

            $destructor = strtolower($method->name) === '__destruct';
            $scope = new ErrorAnalysisScope(
                $destructor ? 'destructor' : 'callable',
                $method->errorContract,
                $class->fullyQualifiedName,
                $this->resolveVariableTypes($methodNode, $class->fullyQualifiedName),
            );
            $flow = $this->analyzeStatements($methodNode->stmts ?? [], $scope);
            $this->diagnoseEscapes($flow->escapingErrors, $scope);
            $this->checkOverride($class, $method);
        }
    }

    /** @param array<Stmt> $statements */
    private function analyzeStatements(array $statements, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($statements as $statement) {
            $flow = $flow->continueWith($this->analyzeNode($statement, $scope));
        }

        return $flow;
    }

    private function analyzeNode(Node $node, ErrorAnalysisScope $scope): ErrorFlow
    {
        if ($node instanceof Expr) {
            $when = $this->context->model->whenExpressions->findPlaceholder($node);

            if ($when !== null) {
                return $this->analyzeWhenExpression($when, $scope);
            }
        }

        if ($node instanceof Stmt\Function_) {
            $namespace = $this->sourceNames->resolveNamespaceAt(
                $this->context->parsedFile,
                $this->resolveSpan($node)->start->offset,
            );
            $this->analyzeFunction($node, $namespace);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $namespace = $this->sourceNames->resolveNamespaceAt(
                $this->context->parsedFile,
                $this->resolveSpan($node)->start->offset,
            );
            $this->analyzeClass($node, $namespace);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Stmt\TryCatch) {
            return $this->analyzeTry($node, $scope);
        }

        if ($node instanceof Stmt\If_) {
            return $this->analyzeIf($node, $scope);
        }
        if (
            $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
        ) {
            return $this->analyzeLoop($node, $scope);
        }

        if ($node instanceof Expr\Closure) {
            $anonymous = new ErrorAnalysisScope(
                'anonymous',
                null,
                $scope->currentClass,
                $this->resolveVariableTypes($node, $scope->currentClass),
            );
            $flow = $this->analyzeStatements($node->stmts, $anonymous);
            $this->diagnoseEscapes($flow->escapingErrors, $anonymous);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Expr\ArrowFunction) {
            $anonymous = new ErrorAnalysisScope(
                'anonymous',
                null,
                $scope->currentClass,
                $this->resolveVariableTypes($node, $scope->currentClass),
            );
            $flow = $this->analyzeNode($node->expr, $anonymous);
            $this->diagnoseEscapes($flow->escapingErrors, $anonymous);

            return ErrorFlow::createEmpty();
        }

        if ($node instanceof Expr\Throw_) {
            $nested = $this->analyzeNode($node->expr, $scope);
            $errors = $nested->escapingErrors;

            foreach ($this->resolveThrowableNames($this->resolveExpressionType($node->expr, $scope)) as $type) {
                if ($this->hierarchy->classify($type) === ThrowableKind::Checked) {
                    $errors = $errors->combine($this->createSingleErrorSet($type, $this->resolveSpan($node)));
                }
            }

            return new ErrorFlow($errors, false);
        }

        if ($node instanceof Expr\FuncCall) {
            return $this->analyzeFunctionCall($node, $scope);
        }

        if ($node instanceof Expr\StaticCall) {
            return $this->analyzeStaticCall($node, $scope);
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $this->analyzeMethodCall($node, $scope);
        }

        if ($node instanceof Expr\New_) {
            return $this->analyzeNew($node, $scope);
        }

        if ($node instanceof Stmt\Return_ || $node instanceof Expr\Exit_) {
            $nested = ErrorFlow::createEmpty();

            foreach ($this->resolveChildren($node) as $child) {
                $nested = $nested->continueWith($this->analyzeNode($child, $scope));
            }

            return new ErrorFlow($nested->escapingErrors, false);
        }

        $flow = ErrorFlow::createEmpty();

        foreach ($this->resolveChildren($node) as $child) {
            $flow = $flow->continueWith($this->analyzeNode($child, $scope));
        }

        return $flow;
    }

    private function analyzeWhenExpression(
        WhenExpressionAnalysis $when,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        $errors = new ErrorSet();

        foreach ($when->branches as $branch) {
            if ($branch->condition !== null) {
                $errors = $errors->combine(
                    $this->analyzeNode($branch->condition, $scope)->escapingErrors,
                );
            }

            $errors = $errors->combine(
                $this->analyzeStatements($branch->statements, $scope)->escapingErrors,
            );
        }

        // Branch-level returns yield the expression value. They do not terminate
        // the enclosing PHP statement, so the lowered expression completes here.
        return new ErrorFlow($errors, true);
    }

    private function analyzeFunctionCall(Expr\FuncCall $call, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($call->args, $scope);

        if (!$call->name instanceof Node\Name) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $contract = $this->resolveFunctionSymbol($call->name)?->errorContract;

        if ($contract === null) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        return $flow->continueWith($this->flowFromContract($contract, $this->resolveSpan($call)));
    }

    private function analyzeStaticCall(Expr\StaticCall $call, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($call->args, $scope);

        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $className = $this->resolveClassName($call->class, $scope);
        $method = $className === null ? null : $this->findMethod($className, $call->name->toString());

        if ($method === null) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        return $flow->continueWith($this->flowFromContract($method->errorContract, $this->resolveSpan($call)));
    }

    private function analyzeMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        $flow = $this->analyzeNode($call->var, $scope)->continueWith($this->analyzeArguments($call->args, $scope));

        if (!$call->name instanceof Node\Identifier) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $resolution = $this->resolveMethodTargets(
            $this->resolveExpressionType($call->var, $scope),
            $call->name->toString(),
        );
        $seen = [];

        foreach ($resolution['targets'] as $target) {
            $key = spl_object_id($target['method']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $flow = $flow->continueWith($this->flowFromContract($target['method']->errorContract, $this->resolveSpan($call)));
        }

        if (!$resolution['complete'] || $resolution['targets'] === []) {
            $this->addDynamicBoundary($this->resolveSpan($call));
        }

        return $flow;
    }

    private function analyzeNew(Expr\New_ $new, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = $this->analyzeArguments($new->args, $scope);

        if (!$new->class instanceof Node\Name) {
            $this->addDynamicBoundary($this->resolveSpan($new));

            return $flow;
        }

        $className = $this->resolveClassName($new->class, $scope);

        if ($className === null) {
            $this->addDynamicBoundary($this->resolveSpan($new));

            return $flow;
        }

        if ($this->context->symbols->findClass($className) === null) {
            if ($this->hierarchy->classify($className) === ThrowableKind::Unknown) {
                $this->addDynamicBoundary($this->resolveSpan($new));
            }

            return $flow;
        }

        $constructor = $this->findMethod($className, '__construct');

        return $constructor === null ? $flow : $flow->continueWith($this->flowFromContract($constructor->errorContract, $this->resolveSpan($new)));
    }

    private function analyzeTry(Stmt\TryCatch $try, ErrorAnalysisScope $scope): ErrorFlow
    {
        $tryFlow = $this->analyzeStatements($try->stmts, $scope);
        $remaining = $tryFlow->escapingErrors;
        $catchErrors = new ErrorSet();
        $catchMayComplete = false;
        $previousCaught = [];

        foreach ($try->catches as $catch) {
            $caught = [];

            foreach ($catch->types as $type) {
                $resolved = $this->context->resolvedNames->resolve($type) ?? $type->toString();
                $caught[] = $resolved;

                if ($this->hierarchy->classify($resolved) === ThrowableKind::NotThrowable) {
                    $this->addDiagnostic(
                        DiagnosticCode::ErrorTypeNotThrowable,
                        Severity::Error,
                        'Error Type Is Not Throwable',
                        sprintf('%s is not a valid catch type.', $resolved),
                        $this->resolveSpan($type),
                    );
                }

                foreach ($previousCaught as $earlier) {
                    if ($this->hierarchy->matchesSubtype($resolved, $earlier)) {
                        $this->addDiagnostic(
                            DiagnosticCode::ErrorCatchUnreachable,
                            Severity::Error,
                            'Error Catch Is Unreachable',
                            sprintf('%s is already handled by an earlier catch.', $resolved),
                            $this->resolveSpan($type),
                        );
                        break;
                    }
                }
            }

            $remaining = $remaining->excludeCaught($caught, $this->hierarchy);
            array_push($previousCaught, ...$caught);
            $catchScope = $scope;

            if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
                $catchScope = $scope->includeVariable(
                    '$' . $catch->var->name,
                    $this->combineTypes(array_map(static fn (string $type): Type => new AtomicType($type), $caught)),
                );
            }

            $catchFlow = $this->analyzeStatements($catch->stmts, $catchScope);
            $catchErrors = $catchErrors->combine($catchFlow->escapingErrors);
            $catchMayComplete = $catchMayComplete || $catchFlow->mayCompleteNormally;
        }

        $beforeFinally = $remaining->combine($catchErrors);
        $beforeFinallyMayComplete = $tryFlow->mayCompleteNormally || $catchMayComplete;

        if ($try->finally === null) {
            return new ErrorFlow($beforeFinally, $beforeFinallyMayComplete);
        }

        $finallyFlow = $this->analyzeStatements($try->finally->stmts, $scope);

        return $finallyFlow->mayCompleteNormally
            ? new ErrorFlow($beforeFinally->combine($finallyFlow->escapingErrors), $beforeFinallyMayComplete)
            : $finallyFlow;
    }

    private function analyzeIf(Stmt\If_ $if, ErrorAnalysisScope $scope): ErrorFlow
    {
        $errors = $this->analyzeNode($if->cond, $scope)->escapingErrors;
        $branches = [$this->analyzeStatements($if->stmts, $scope)];

        foreach ($if->elseifs as $elseif) {
            $condition = $this->analyzeNode($elseif->cond, $scope);
            $branch = $this->analyzeStatements($elseif->stmts, $scope);
            $errors = $errors->combine($condition->escapingErrors)->combine($branch->escapingErrors);
            $branches[] = $branch;
        }

        if ($if->else === null) {
            return new ErrorFlow($errors->combine($branches[0]->escapingErrors), true);
        }

        $else = $this->analyzeStatements($if->else->stmts, $scope);
        $errors = $errors->combine($branches[0]->escapingErrors)->combine($else->escapingErrors);
        $mayComplete = $else->mayCompleteNormally;

        foreach ($branches as $branch) {
            $mayComplete = $mayComplete || $branch->mayCompleteNormally;
        }

        return new ErrorFlow($errors, $mayComplete);
    }

    private function analyzeLoop(
        Stmt\For_|Stmt\Foreach_|Stmt\While_|Stmt\Do_ $loop,
        ErrorAnalysisScope $scope,
    ): ErrorFlow {
        if ($loop instanceof Stmt\Do_) {
            $body = $this->analyzeStatements($loop->stmts, $scope);
            $condition = $this->analyzeNode($loop->cond, $scope);

            return new ErrorFlow(
                $body->escapingErrors->combine($condition->escapingErrors),
                $body->mayCompleteNormally,
            );
        }

        $errors = new ErrorSet();

        foreach ($this->resolveChildren($loop) as $child) {
            $errors = $errors->combine($this->analyzeNode($child, $scope)->escapingErrors);
        }

        return new ErrorFlow($errors, true);
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function analyzeArguments(array $arguments, ErrorAnalysisScope $scope): ErrorFlow
    {
        $flow = ErrorFlow::createEmpty();

        foreach ($arguments as $argument) {
            if ($argument instanceof Node\Arg) {
                $flow = $flow->continueWith($this->analyzeNode($argument->value, $scope));
            }
        }

        return $flow;
    }

    private function flowFromContract(CallableErrorContract $contract, Span $callSpan): ErrorFlow
    {
        $errors = new ErrorSet();

        foreach ($contract->filterCheckedErrors($this->hierarchy) as $declared) {
            $errors->add(new ErrorOccurrence($declared->canonicalType, $callSpan, $declared->span));
        }

        return new ErrorFlow($errors);
    }

    private function resolveExpressionType(Expr $expression, ErrorAnalysisScope $scope): Type
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            return $this->resolveNodeType($expression->class, $scope);
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->variableTypes['$' . $expression->name] ?? new UnknownType();
        }

        if (
            ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch)
            && $expression->name instanceof Node\Identifier
        ) {
            $resolution = $this->resolvePropertyTargets(
                $this->resolveExpressionType($expression->var, $scope),
                $expression->name->toString(),
            );
            $types = [];

            foreach ($resolution['targets'] as $target) {
                if ($target['property']->type === null) {
                    continue;
                }

                $types[] = $this->resolveTargetType(
                    $this->types->parse($target['property']->type->text),
                    $target['receiver'],
                    $target['substitutions'],
                );
            }

            if ($expression instanceof Expr\NullsafePropertyFetch && $types !== []) {
                $types[] = new AtomicType('null');
            }

            return $this->combineTypes($types);
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $type = $this->resolveFunctionSymbol($expression->name)?->returnType;

            return $type === null ? new UnknownType() : $this->types->parse($type->text);
        }

        if (
            $expression instanceof Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
        ) {
            $resolution = $this->resolveMethodTargets(
                $this->resolveNodeType($expression->class, $scope),
                $expression->name->toString(),
            );

            return $this->resolveMethodReturnType($resolution['targets'], false);
        }

        if (
            ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier
        ) {
            $resolution = $this->resolveMethodTargets(
                $this->resolveExpressionType($expression->var, $scope),
                $expression->name->toString(),
            );

            return $this->resolveMethodReturnType(
                $resolution['targets'],
                $expression instanceof Expr\NullsafeMethodCall,
            );
        }

        return new UnknownType();
    }

    /**
     * @param list<array{method: MethodSymbol, receiver: Type, substitutions: array<string, Type>}> $targets
     */
    private function resolveMethodReturnType(array $targets, bool $nullable): Type
    {
        $types = [];

        foreach ($targets as $target) {
            if ($target['method']->returnType === null) {
                continue;
            }

            $types[] = $this->resolveTargetType(
                $this->types->parse($target['method']->returnType->text),
                $target['receiver'],
                $target['substitutions'],
            );
        }

        if ($nullable && $types !== []) {
            $types[] = new AtomicType('null');
        }

        return $this->combineTypes($types);
    }

    /** @param array<string, Type> $substitutions */
    private function resolveTargetType(Type $type, Type $receiver, array $substitutions): Type
    {
        $type = $this->resolveContextualType($type, $receiver);

        return (new TypeSubstitution($substitutions))->substitute($type);
    }

    private function resolveContextualType(Type $type, Type $receiver): Type
    {
        if ($type instanceof AtomicType && in_array($type->canonical, ['self', 'static'], true)) {
            return $receiver;
        }

        if ($type instanceof GenericType) {
            return new GenericType(
                $type->base,
                array_map(fn (Type $argument): Type => $this->resolveContextualType($argument, $receiver), $type->arguments),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->resolveContextualType($type->keyType, $receiver),
                $this->resolveContextualType($type->valueType, $receiver),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(fn (Type $member): Type => $this->resolveContextualType($member, $receiver), $type->members);

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    /** @param list<Type> $types */
    private function combineTypes(array $types): Type
    {
        $unique = [];

        foreach ($types as $type) {
            if (!$type->isUnknown) {
                $unique[$type->canonical] = $type;
            }
        }

        return match (count($unique)) {
            0 => new UnknownType(),
            1 => array_values($unique)[0],
            default => new UnionType(array_values($unique)),
        };
    }

    private function resolveClassName(Node\Name $name, ErrorAnalysisScope $scope): ?string
    {
        $lower = strtolower($name->toString());

        if (in_array($lower, ['self', 'static'], true)) {
            return $scope->currentClass;
        }

        if ($lower === 'parent') {
            return $scope->currentClass === null
                ? null
                : $this->context->symbols->findClass($scope->currentClass)?->parent;
        }

        $resolved = $this->context->resolvedNames->resolve($name);

        if ($resolved !== null) {
            return $resolved;
        }

        $originalOffset = $name->getAttribute('ppphpOriginalStart');

        return is_int($originalOffset)
            ? $this->sourceNames->resolve(
                $this->context->parsedFile,
                $name->toString(),
                $originalOffset,
            )
            : $name->toString();
    }

    private function resolveNodeType(Node $type, ErrorAnalysisScope $scope): Type
    {
        if ($type instanceof Node\Identifier) {
            return new AtomicType($type->toString());
        }

        if ($type instanceof Node\Name) {
            $offset = $this->resolveSpan($type)->start->offset;
            $applied = $this->findAppliedType($offset);

            if ($applied !== null) {
                return $this->qualifyType(
                    $this->types->parse($applied->span->text),
                    $offset,
                    $scope->currentClass,
                );
            }

            $resolved = $this->resolveClassName($type, $scope);

            return $resolved === null ? new UnknownType() : new AtomicType($resolved);
        }

        if ($type instanceof Node\NullableType) {
            return new UnionType([$this->resolveNodeType($type->type, $scope), new AtomicType('null')]);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $members = array_values(array_map(fn (Node $member): Type => $this->resolveNodeType($member, $scope), $type->types));

            if ($members === []) {
                return new UnknownType();
            }

            return $type instanceof Node\UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return new UnknownType();
    }

    private function qualifyType(Type $type, int $offset, ?string $currentClass): Type
    {
        if ($type instanceof AtomicType) {
            if ($type->isBuiltin) {
                return $type;
            }

            $lower = $type->canonical;

            if (in_array($lower, ['self', 'static'], true) && $currentClass !== null) {
                return new AtomicType($currentClass);
            }

            if ($lower === 'parent' && $currentClass !== null) {
                $parent = $this->context->symbols->findClass($currentClass)?->parent;

                return $parent === null ? new UnknownType() : new AtomicType($parent);
            }

            $parameter = $this->context->genericDeclarations->findVisibleParameter(
                $this->context->parsedFile->sourceFile,
                $offset,
                $type->name,
            );

            if ($parameter !== null) {
                return $parameter;
            }

            return new AtomicType($this->sourceNames->resolve(
                $this->context->parsedFile,
                $type->name,
                $offset,
            ));
        }

        if ($type instanceof TypeParameter) {
            return $type;
        }

        if ($type instanceof GenericType) {
            $base = $this->qualifyType($type->base, $offset, $currentClass);

            return new GenericType(
                $base instanceof AtomicType ? $base : $type->base,
                array_map(fn (Type $argument): Type => $this->qualifyType($argument, $offset, $currentClass), $type->arguments),
            );
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->qualifyType($type->keyType, $offset, $currentClass),
                $this->qualifyType($type->valueType, $offset, $currentClass),
                $type->isList,
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $members = array_map(fn (Type $member): Type => $this->qualifyType($member, $offset, $currentClass), $type->members);

            return $type instanceof UnionType ? new UnionType($members) : new IntersectionType($members);
        }

        return $type;
    }

    private function findAppliedType(int $offset): ?\Amasiye\Ppphp\Frontend\Ast\GenericType
    {
        foreach ($this->context->parsedFile->extensionSyntax->genericTypes as $reference) {
            if ($reference->nameSpan->start->offset === $offset) {
                return $reference;
            }
        }

        return null;
    }

    private function resolveFunctionSymbol(Node\Name $name): ?FunctionSymbol
    {
        $qualified = $this->sourceNames->resolve(
            $this->context->parsedFile,
            $name->toString(),
            $name->getStartFilePos(),
        );
        $symbol = $this->context->symbols->findFunction($qualified);

        if ($symbol !== null) {
            return $symbol;
        }

        $resolved = $this->context->resolvedNames->resolve($name) ?? $name->toString();

        return $this->context->symbols->findFunction($resolved);
    }

    private function findMethod(string $className, string $methodName): ?MethodSymbol
    {
        $resolution = $this->resolveMethodTargets(new AtomicType($className), $methodName);

        return $resolution['targets'][0]['method'] ?? null;
    }

    /**
     * @return array{
     *     targets: list<array{method: MethodSymbol, receiver: Type, substitutions: array<string, Type>}>,
     *     complete: bool
     * }
     */
    private function resolveMethodTargets(Type $type, string $methodName): array
    {
        if ($type instanceof UnionType) {
            $targets = [];
            $complete = true;
            $resolvedMember = false;

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                $resolvedMember = true;
                $resolution = $this->resolveMethodTargets($member, $methodName);
                array_push($targets, ...$resolution['targets']);
                $complete = $complete && $resolution['complete'];
            }

            return ['targets' => $targets, 'complete' => $resolvedMember && $complete];
        }

        if ($type instanceof IntersectionType) {
            $targets = [];

            foreach ($type->members as $member) {
                $resolution = $this->resolveMethodTargets($member, $methodName);
                array_push($targets, ...$resolution['targets']);
            }

            return ['targets' => $targets, 'complete' => $targets !== []];
        }

        if ($type instanceof TypeParameter) {
            return $type->bound === null
                ? ['targets' => [], 'complete' => false]
                : $this->resolveMethodTargets($type->bound, $methodName);
        }

        if (!$type instanceof AtomicType && !$type instanceof GenericType) {
            return ['targets' => [], 'complete' => false];
        }

        $visited = [];
        $target = $this->findMethodTargetInHierarchy($type, $methodName, $visited);

        return [
            'targets' => $target === null ? [] : [$target],
            'complete' => $target !== null,
        ];
    }

    /**
     * @param array<string, true> $visited
     * @return array{method: MethodSymbol, receiver: Type, substitutions: array<string, Type>}|null
     */
    private function findMethodTargetInHierarchy(AtomicType|GenericType $receiver, string $methodName, array &$visited): ?array
    {
        $className = $receiver instanceof GenericType ? $receiver->base->name : $receiver->name;
        $key = strtolower(ltrim($className, '\\')) . '<' . $receiver->canonical . '>';

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $class = $this->context->symbols->findClass($className);

        if ($class === null) {
            return null;
        }

        $substitutions = $this->resolveClassSubstitutions($class, $receiver);
        $method = $class->findMethod($methodName);

        if ($method !== null) {
            return [
                'method' => $method,
                'receiver' => $receiver,
                'substitutions' => $substitutions,
            ];
        }

        foreach ($this->resolveRelatedTypes($class) as $related) {
            $related = (new TypeSubstitution($substitutions))->substitute($related);

            if (!$related instanceof AtomicType && !$related instanceof GenericType) {
                continue;
            }

            $target = $this->findMethodTargetInHierarchy(
                $related,
                $methodName,
                $visited,
            );

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     targets: list<array{property: PropertySymbol, receiver: Type, substitutions: array<string, Type>}>,
     *     complete: bool
     * }
     */
    private function resolvePropertyTargets(Type $type, string $propertyName): array
    {
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $targets = [];
            $complete = $type instanceof UnionType;
            $resolvedMember = false;

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                $resolvedMember = true;
                $resolution = $this->resolvePropertyTargets($member, $propertyName);
                array_push($targets, ...$resolution['targets']);
                $complete = $type instanceof UnionType
                    ? $complete && $resolution['complete']
                    : $complete || $resolution['targets'] !== [];
            }

            return ['targets' => $targets, 'complete' => $resolvedMember && $complete];
        }

        if ($type instanceof TypeParameter) {
            return $type->bound === null
                ? ['targets' => [], 'complete' => false]
                : $this->resolvePropertyTargets($type->bound, $propertyName);
        }

        if (!$type instanceof AtomicType && !$type instanceof GenericType) {
            return ['targets' => [], 'complete' => false];
        }

        $visited = [];
        $target = $this->findPropertyTargetInHierarchy($type, $propertyName, $visited);

        return ['targets' => $target === null ? [] : [$target], 'complete' => $target !== null];
    }

    /**
     * @param array<string, true> $visited
     * @return array{property: PropertySymbol, receiver: Type, substitutions: array<string, Type>}|null
     */
    private function findPropertyTargetInHierarchy(AtomicType|GenericType $receiver, string $propertyName, array &$visited): ?array
    {
        $className = $receiver instanceof GenericType ? $receiver->base->name : $receiver->name;
        $key = strtolower(ltrim($className, '\\')) . '<' . $receiver->canonical . '>';

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $class = $this->context->symbols->findClass($className);

        if ($class === null) {
            return null;
        }

        $substitutions = $this->resolveClassSubstitutions($class, $receiver);
        $property = $class->findProperty($propertyName);

        if ($property !== null) {
            return [
                'property' => $property,
                'receiver' => $receiver,
                'substitutions' => $substitutions,
            ];
        }

        foreach ($this->resolveRelatedTypes($class) as $related) {
            $related = (new TypeSubstitution($substitutions))->substitute($related);

            if (!$related instanceof AtomicType && !$related instanceof GenericType) {
                continue;
            }

            $target = $this->findPropertyTargetInHierarchy(
                $related,
                $propertyName,
                $visited,
            );

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /** @return array<string, Type> */
    private function resolveClassSubstitutions(ClassSymbol $class, Type $receiver): array
    {
        if (!$receiver instanceof GenericType || $class->genericDeclaration === null) {
            return [];
        }

        $substitutions = [];

        foreach ($class->genericDeclaration->parameters as $index => $parameter) {
            $argument = $receiver->arguments[$index] ?? null;

            if ($argument === null) {
                continue;
            }

            $substitutions[$parameter->canonical] = $argument;
            $substitutions[strtolower($parameter->name)] = $argument;
        }

        return $substitutions;
    }

    /** @return list<Type> */
    private function resolveRelatedTypes(ClassSymbol $class): array
    {
        $related = [];

        foreach ($class->traitTypes as $type) {
            $related[] = $this->types->parse($type->text);
        }

        foreach ($class->interfaceTypes as $type) {
            $related[] = $this->types->parse($type->text);
        }

        if ($class->parentType !== null) {
            $related[] = $this->types->parse($class->parentType->text);
        }

        if ($related !== []) {
            return $related;
        }

        foreach ([...$class->traits, ...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $name) {
            $related[] = new AtomicType($name);
        }

        return $related;
    }

    private function checkOverride(ClassSymbol $class, MethodSymbol $method): void
    {
        if ($method->visibility === 'private' || strtolower($method->name) === '__construct') {
            return;
        }

        foreach ($this->resolveInheritedMethods($class, $method->name) as $inherited) {
            if ($inherited->visibility === 'private') {
                continue;
            }

            $incompatible = $this->effectCompatibility->filterIncompatibleErrors(
                $method->errorContract,
                $inherited->errorContract,
                $this->hierarchy,
            );

            foreach ($incompatible as $childError) {
                $this->addDiagnostic(
                    DiagnosticCode::CheckedErrorDeclarationNotCovariant,
                    Severity::Error,
                    'Checked Error Declaration Is Not Covariant',
                    sprintf('%s is not permitted by the inherited %s::%s() contract.', $childError->canonicalType, $inherited->owner, $inherited->name),
                    $childError->span,
                    [new DiagnosticLabel($inherited->declarationSpan, 'The inherited contract is declared here.')],
                );
            }
        }
    }

    /** @return list<MethodSymbol> */
    private function resolveInheritedMethods(ClassSymbol $class, string $methodName): array
    {
        $methods = [];

        foreach ([...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $ancestorName) {
            $ancestor = $this->context->symbols->findClass($ancestorName);

            if ($ancestor === null) {
                continue;
            }

            $method = $ancestor->findMethod($methodName);

            if ($method !== null) {
                $methods[] = $method;
            }

            array_push($methods, ...$this->resolveInheritedMethods($ancestor, $methodName));
        }

        return $methods;
    }

    private function diagnoseEscapes(ErrorSet $errors, ErrorAnalysisScope $scope): void
    {
        foreach ($errors as $error) {
            if ($scope->contract?->covers($error, $this->hierarchy) === true) {
                continue;
            }

            [$code, $title, $message] = match ($scope->kind) {
                'file' => [
                    DiagnosticCode::CheckedErrorCannotEscapeFileScope,
                    'Checked Error Cannot Escape File Scope',
                    sprintf('%s must be caught before it escapes executable file scope.', $error->canonicalType),
                ],
                'anonymous' => [
                    DiagnosticCode::CheckedErrorCannotEscapeAnonymousCallable,
                    'Checked Error Cannot Escape Anonymous Callable',
                    sprintf('%s must be caught inside this anonymous callable.', $error->canonicalType),
                ],
                'destructor' => [
                    DiagnosticCode::CheckedErrorCannotEscapeDestructor,
                    'Checked Error Cannot Escape Destructor',
                    sprintf('%s must be caught before it escapes this destructor.', $error->canonicalType),
                ],
                default => [
                    DiagnosticCode::CheckedErrorNotHandled,
                    'Checked Error Is Not Handled',
                    sprintf('%s is not caught and is not covered by the enclosing callable throws clause.', $error->canonicalType),
                ],
            };
            $related = [];

            if ($error->declarationSpan !== null) {
                $related[] = new DiagnosticLabel(
                    $error->declarationSpan,
                    'The called error contract is declared here.',
                );
            }

            if ($scope->contract !== null) {
                $related[] = new DiagnosticLabel(
                    $scope->contract->ownerSpan,
                    'The enclosing callable is declared here.',
                );
            }

            $this->addDiagnostic($code, Severity::Error, $title, $message, $error->span, $related);
        }
    }

    /**
     * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $owner
     * @return array<string, Type>
     */
    private function resolveVariableTypes(Node $owner, ?string $currentClass): array
    {
        $variables = [];
        $typeScope = new ErrorAnalysisScope('types', null, $currentClass);

        foreach ($owner->params as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            if ($parameter->type !== null) {
                $variables['$' . $parameter->var->name] = $this->resolveNodeType($parameter->type, $typeScope);
            }
        }

        if ($currentClass !== null) {
            $class = $this->context->symbols->findClass($currentClass);
            $parameters = $class === null || $class->genericDeclaration === null
                ? []
                : $class->genericDeclaration->parameters;
            $variables['$this'] = $parameters === []
                ? new AtomicType($currentClass)
                : new GenericType(new AtomicType($currentClass), $parameters);
        }

        $ownerSpan = $this->resolveSpan($owner);

        foreach ($this->context->model->bindings->bindings as $binding) {
            if (
                $binding->declarationSpan->start->offset < $ownerSpan->start->offset
                || $binding->declarationSpan->end->offset > $ownerSpan->end->offset
                || !$this->belongsToCallable($owner, $binding->variableSpan)
            ) {
                continue;
            }

            $variables[$binding->name] = $this->qualifyType(
                $binding->type->semanticType,
                $binding->declarationSpan->start->offset,
                $currentClass,
            );
        }

        return $variables;
    }

    /** @return array<string, Type> */
    private function resolveFileVariableTypes(): array
    {
        $variables = [];

        foreach ($this->context->model->bindings->bindings as $binding) {
            $insideCallable = false;

            foreach ($this->context->parsedFile->statements as $statement) {
                if ($this->isInsideCallable($statement, $binding->variableSpan)) {
                    $insideCallable = true;
                    break;
                }
            }

            if (!$insideCallable) {
                $variables[$binding->name] = $this->qualifyType(
                    $binding->type->semanticType,
                    $binding->declarationSpan->start->offset,
                    null,
                );
            }
        }

        return $variables;
    }

    private function isInsideCallable(Node $node, Span $variableSpan): bool
    {
        $span = $this->resolveSpan($node);

        if ($span->end->offset <= $variableSpan->start->offset || $span->start->offset >= $variableSpan->end->offset) {
            return false;
        }

        if (
            $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            return true;
        }

        foreach ($this->resolveChildren($node) as $child) {
            if ($this->isInsideCallable($child, $variableSpan)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function resolveThrowableNames(Type $type): array
    {
        if ($type instanceof AtomicType) {
            return $type->isBuiltin ? [] : [$type->name];
        }

        if ($type instanceof GenericType) {
            return [$type->base->name];
        }

        if ($type instanceof TypeParameter) {
            return $type->bound === null ? [] : $this->resolveThrowableNames($type->bound);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];

            foreach ($type->members as $member) {
                foreach ($this->resolveThrowableNames($member) as $name) {
                    $names[strtolower(ltrim($name, '\\'))] = $name;
                }
            }

            return array_values($names);
        }

        return [];
    }

    private function belongsToCallable(Node $owner, Span $variableSpan): bool
    {
        foreach ($this->resolveChildren($owner) as $child) {
            if (
                $this->resolveSpan($child)->end->offset <= $variableSpan->start->offset
                || $this->resolveSpan($child)->start->offset >= $variableSpan->end->offset
            ) {
                continue;
            }

            if ($child instanceof Expr\Closure || $child instanceof Expr\ArrowFunction) {
                return false;
            }

            return $this->belongsToCallable($child, $variableSpan);
        }

        return true;
    }

    /** @return list<Node> */
    private function resolveChildren(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $children[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $children[] = $child;
                    }
                }
            }
        }

        return $children;
    }

    private function createSingleErrorSet(string $type, Span $span): ErrorSet
    {
        $set = new ErrorSet();
        $set->add(new ErrorOccurrence($type, $span));

        return $set;
    }

    private function addDynamicBoundary(Span $span): void
    {
        $this->addDiagnostic(
            DiagnosticCode::UncheckedCallBoundary,
            Severity::Warning,
            'Unchecked Call Boundary',
            'The checked-error contract cannot be determined for this invocation.',
            $span,
        );
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        Severity $severity,
        string $title,
        string $message,
        Span $span,
        array $related = [],
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $severity,
            $title,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
            $code === DiagnosticCode::CheckedErrorNotHandled
                ? 'Catch the checked error or add it to the enclosing callable throws clause.'
                : null,
        ));
    }

    private function resolveSpan(Node $node): Span
    {
        $originalStart = $node->getAttribute('ppphpOriginalStart');
        $originalEnd = $node->getAttribute('ppphpOriginalEnd');

        if (is_int($originalStart) && is_int($originalEnd)) {
            return $this->context->parsedFile->sourceFile->createSpan($originalStart, $originalEnd);
        }

        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $start < 0 || $end < $start
            ? $this->context->parsedFile->sourceFile->createSpan(0, 0)
            : $this->context->parsedFile->sourceFile->createSpan($start, $end + 1);
    }
}
