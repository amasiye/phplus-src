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
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->hierarchy = new ThrowableHierarchy($context->symbols);
        $scope = new ErrorAnalysisScope('file', null, null);
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

            foreach ($this->resolveExpressionTypes($node->expr, $scope) as $type) {
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

        $types = $this->resolveExpressionTypes($call->var, $scope);

        if ($types === []) {
            $this->addDynamicBoundary($this->resolveSpan($call));

            return $flow;
        }

        $unresolved = false;
        foreach ($types as $type) {
            $method = $this->findMethod($type, $call->name->toString());

            if ($method === null) {
                $unresolved = true;
                continue;
            }

            $flow = $flow->continueWith($this->flowFromContract($method->errorContract, $this->resolveSpan($call)));
        }

        if ($unresolved) {
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
                $catchScope = $scope->includeVariable('$' . $catch->var->name, $caught);
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

    /** @return list<string> */
    private function resolveExpressionTypes(Expr $expression, ErrorAnalysisScope $scope): array
    {
        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $name = $this->resolveClassName($expression->class, $scope);

            return $name === null ? [] : [$name];
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->variableTypes['$' . $expression->name] ?? [];
        }

        if (
            $expression instanceof Expr\PropertyFetch
            && $expression->name instanceof Node\Identifier
        ) {
            foreach ($this->resolveExpressionTypes($expression->var, $scope) as $owner) {
                $property = $this->context->symbols->findClass($owner)?->findProperty(
                    $expression->name->toString(),
                );

                if ($property?->type !== null) {
                    return $this->resolveObjectTypes($property->type->text, $scope);
                }
            }

            return [];
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $type = $this->resolveFunctionSymbol($expression->name)?->returnType;

            return $type === null ? [] : $this->resolveObjectTypes($type->text, $scope);
        }

        if (
            $expression instanceof Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
        ) {
            $owner = $this->resolveClassName($expression->class, $scope);
            $type = $owner === null
                ? null
                : $this->findMethod($owner, $expression->name->toString())?->returnType;

            return $type === null ? [] : $this->resolveObjectTypes($type->text, $scope);
        }

        if (
            ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier
        ) {
            foreach ($this->resolveExpressionTypes($expression->var, $scope) as $owner) {
                $type = $this->findMethod($owner, $expression->name->toString())?->returnType;

                if ($type !== null) {
                    return $this->resolveObjectTypes($type->text, $scope);
                }
            }
        }

        return [];
    }

    /** @return list<string> */
    private function resolveObjectTypes(string $type, ErrorAnalysisScope $scope): array
    {
        $type = str_starts_with($type, '?') ? substr($type, 1) . '|null' : $type;
        $objects = [];

        foreach (explode('|', $type) as $member) {
            $member = trim($member, " \\t\\n\\r\\0\\x0B()");

            if (
                $member === ''
                || str_contains($member, '&')
                || in_array(strtolower($member), [
                    'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
                    'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
                ], true)
            ) {
                continue;
            }

            $lower = strtolower($member);

            if (in_array($lower, ['self', 'static'], true) && $scope->currentClass !== null) {
                $member = $scope->currentClass;
            } elseif ($lower === 'parent' && $scope->currentClass !== null) {
                $class = $this->context->symbols->findClass($scope->currentClass);

                if ($class !== null && $class->parent !== null) {
                    $member = $class->parent;
                }
            }

            $objects[strtolower(ltrim($member, '\\'))] = ltrim($member, '\\');
        }

        return array_values($objects);
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
        $visited = [];

        return $this->findMethodInHierarchy($className, $methodName, $visited);
    }

    /** @param array<string, true> $visited */
    private function findMethodInHierarchy(string $className, string $methodName, array &$visited): ?MethodSymbol
    {
        $key = strtolower(ltrim($className, '\\'));

        if (isset($visited[$key])) {
            return null;
        }

        $visited[$key] = true;
        $class = $this->context->symbols->findClass($className);
        $method = $class?->findMethod($methodName);

        if ($method !== null) {
            return $method;
        }

        if ($class === null) {
            return null;
        }

        foreach ([...$class->traits, ...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $ancestor) {
            $method = $this->findMethodInHierarchy($ancestor, $methodName, $visited);

            if ($method !== null) {
                return $method;
            }
        }

        return null;
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
     * @return array<string, list<string>>
     */
    private function resolveVariableTypes(Node $owner, ?string $currentClass): array
    {
        $variables = [];

        foreach ($owner->params as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $types = $this->resolveTypeNames(
                $parameter->type,
                new ErrorAnalysisScope('types', null, $currentClass),
            );

            if ($types !== []) {
                $variables['$' . $parameter->var->name] = $types;
            }
        }

        if ($currentClass !== null) {
            $variables['$this'] = [$currentClass];
        }

        $ownerSpan = $this->resolveSpan($owner);
        $start = $ownerSpan->start->offset;
        $end = $ownerSpan->end->offset;
        $declarations = [
            ...$this->context->parsedFile->extensionSyntax->typedLocals,
            ...$this->context->parsedFile->extensionSyntax->typedForInitializers,
            ...$this->context->parsedFile->extensionSyntax->typedForeachBindings,
        ];

        foreach ($declarations as $declaration) {
            if (
                $declaration->span->start->offset < $start
                || $declaration->span->end->offset > $end
                || !$this->belongsToCallable($owner, $declaration->variableSpan)
            ) {
                continue;
            }

            $types = $this->resolveWrittenObjectTypes(
                $declaration->type->text,
                $declaration->type->span->start->offset,
                $currentClass,
            );

            if ($types !== []) {
                $variables[$declaration->variableSpan->text] = $types;
            }
        }

        return $variables;
    }

    /** @return list<string> */
    private function resolveTypeNames(?Node $type, ErrorAnalysisScope $scope): array
    {
        if ($type instanceof Node\Name) {
            $resolved = $this->resolveClassName($type, $scope);

            return $resolved === null ? [] : [$resolved];
        }

        if ($type instanceof Node\NullableType) {
            return $this->resolveTypeNames($type->type, $scope);
        }

        if ($type instanceof Node\UnionType) {
            $types = [];

            foreach ($type->types as $member) {
                foreach ($this->resolveTypeNames($member, $scope) as $resolved) {
                    $types[strtolower(ltrim($resolved, '\\'))] = $resolved;
                }
            }

            return array_values($types);
        }

        return [];
    }

    /** @return list<string> */
    private function resolveWrittenObjectTypes(string $type, int $offset, ?string $currentClass): array
    {
        $type = trim($type);
        $type = str_starts_with($type, '?') ? substr($type, 1) . '|null' : $type;

        if (str_contains($type, '&') || str_contains($type, '<') || str_contains($type, '>')) {
            return [];
        }

        $types = [];

        foreach (explode('|', $type) as $member) {
            $member = trim($member, " \\t\\n\\r\\0\\x0B()");

            if (in_array(strtolower($member), [
                '', 'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
                'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
            ], true)) {
                continue;
            }

            $lower = strtolower($member);

            if (in_array($lower, ['self', 'static'], true) && $currentClass !== null) {
                $resolved = $currentClass;
            } elseif ($lower === 'parent' && $currentClass !== null) {
                $resolved = $this->context->symbols->findClass($currentClass)?->parent;
            } else {
                $resolved = $this->sourceNames->resolve(
                    $this->context->parsedFile,
                    $member,
                    $offset,
                );
            }

            if ($resolved !== null) {
                $types[strtolower(ltrim($resolved, '\\'))] = $resolved;
            }
        }

        return array_values($types);
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
