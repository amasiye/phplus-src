<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Interop\Php\Intrinsic\CoreTypeRepository;
use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Amasiye\Ppphp\Semantic\Call\CallArgumentBinder;
use Amasiye\Ppphp\Semantic\Call\CallBindingIssue;
use Amasiye\Ppphp\Semantic\Call\CallBindingIssueKind;
use Amasiye\Ppphp\Semantic\Call\BoundCallArgument;
use Amasiye\Ppphp\Semantic\Call\CallableContract;
use Amasiye\Ppphp\Semantic\Call\CallableContractResolver;
use Amasiye\Ppphp\Semantic\Call\CallableResolutionStatus;
use Amasiye\Ppphp\Semantic\Call\GenericCallInference;
use Amasiye\Ppphp\Semantic\Flow\FlowOutcome;
use Amasiye\Ppphp\Semantic\Flow\FlowState;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\Scope\Scope;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\ClassConstantSymbol;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Symbol\VariableSymbol;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\ExpressionResolutionStatus;
use Amasiye\Ppphp\Semantic\Type\ExpressionTypeResolution;
use Amasiye\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\IntersectionType;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Semantic\Type\MemberResolution;
use Amasiye\Ppphp\Semantic\Type\MemberResolutionStatus;
use Amasiye\Ppphp\Semantic\Type\MemberTypeResolver;
use Amasiye\Ppphp\Semantic\Type\SourceTypeResolver;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibility;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibilityResult;
use Amasiye\Ppphp\Semantic\Type\TypeParameter;
use Amasiye\Ppphp\Semantic\Type\TypeSubstitution;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class AnalyzeTypeFlowPass implements SemanticPass
{
    private SemanticContext $context;

    private ExpressionTypeResolver $expressions;

    private CallableContractResolver $callables;

    private MemberTypeResolver $members;

    /** @var array<int, Type> */
    private array $typedLocals = [];

    /** @var array<string, array<string, true>> */
    private array $helperInitializations = [];

    /** @var array<string, true> */
    private array $activeHelpers = [];

    public function __construct(
        private readonly TypeCompatibility $compatibility = new TypeCompatibility(),
        private readonly SourceTypeResolver $sourceTypes = new SourceTypeResolver(),
        private readonly CallArgumentBinder $argumentBinder = new CallArgumentBinder(),
        private readonly GenericCallInference $genericInference = new GenericCallInference(),
        private readonly NodeSpanResolver $spans = new NodeSpanResolver(),
        private readonly NodeFinder $nodes = new NodeFinder(),
        private readonly CoreTypeRepository $coreTypes = new CoreTypeRepository(),
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->expressions = new ExpressionTypeResolver($context, $this->sourceTypes);
        $this->callables = new CallableContractResolver($context);
        $this->members = new MemberTypeResolver($context->symbols);
        $this->typedLocals = [];
        $this->helperInitializations = [];
        $this->activeHelpers = [];

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $this->typedLocals[$declaration->variableSpan->start->offset] = $this->sourceTypes->resolveSourceType(
                $declaration->type,
                $context->parsedFile,
                $context->genericDeclarations,
            );
        }

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $this->typedLocals[$declaration->variableSpan->start->offset] = $this->sourceTypes->resolveSourceType(
                $declaration->type,
                $context->parsedFile,
                $context->genericDeclarations,
            );
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->typedLocals[$binding->variableSpan->start->offset] = $this->sourceTypes->resolveSourceType(
                $binding->type,
                $context->parsedFile,
                $context->genericDeclarations,
            );
        }

        $this->checkTypeNames($context->parsedFile->statements);
        $this->analyzeDeclarations($context->parsedFile->statements);
    }

    /** @param list<Stmt> $statements */
    private function analyzeDeclarations(array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->analyzeDeclarations(array_values($statement->stmts));
                continue;
            }

            if ($statement instanceof Stmt\Function_) {
                $symbol = $this->findFunction($statement);

                if ($symbol !== null) {
                    $this->analyzeCallable(
                        $statement,
                        $statement->stmts,
                        $symbol->parameters,
                        $symbol->effectiveReturnType,
                        null,
                        $symbol->declarationSpan,
                    );
                }

                continue;
            }

            if (!$statement instanceof Stmt\ClassLike || $statement->name === null) {
                $scope = new Scope('file-type-flow');
                $state = new FlowState();
                $this->analyzeNodeExpressions($statement, $scope, $state, null);
                continue;
            }

            $class = $this->findClass($statement);

            if ($class !== null) {
                $this->analyzeClass($statement, $class);
            }
        }
    }

    private function analyzeClass(Stmt\ClassLike $node, ClassSymbol $class): void
    {
        $constructorOutcome = null;
        $constructor = null;

        foreach ($node->getMethods() as $method) {
            $symbol = $class->findMethod($method->name->toString());

            if ($symbol === null || $method->stmts === null) {
                continue;
            }

            $outcome = $this->analyzeCallable(
                $method,
                $method->stmts,
                $symbol->parameters,
                $symbol->effectiveReturnType,
                $class,
                $symbol->declarationSpan,
                $method->isStatic(),
                strtolower($symbol->name) === '__construct',
            );

            if (strtolower($symbol->name) === '__construct') {
                $constructor = $symbol;
                $constructorOutcome = $outcome;
            }
        }

        foreach ($node->stmts as $member) {
            if (!$member instanceof Stmt\Property) {
                continue;
            }

            foreach ($member->hooks as $hook) {
                $property = $member->props[0] ?? null;
                $propertySymbol = $property === null ? null : $class->findProperty($property->name->toString());
                $returnType = strtolower($hook->name->toString()) === 'get'
                    ? $propertySymbol?->effectiveType()
                    : new AtomicType('void');
                $body = $hook->getStmts();

                if ($body !== null) {
                    $this->analyzeCallable(
                        $hook,
                        $body,
                        [],
                        $returnType,
                        $class,
                        $this->span($hook),
                    );
                }
            }
        }

        $this->checkPropertyInitialization($class, $constructor, $constructorOutcome);
    }

    /**
     * @param Node\FunctionLike $callable
     * @param array<Stmt> $statements
     * @param list<\Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol> $parameters
     */
    private function analyzeCallable(
        Node\FunctionLike $callable,
        array $statements,
        array $parameters,
        ?Type $returnType,
        ?ClassSymbol $class,
        Span $declarationSpan,
        bool $static = false,
        bool $constructor = false,
    ): FlowOutcome {
        $scope = $this->createCallableScope($callable, $parameters, $class, $static);
        $state = $this->createInitialState($scope, $class, $constructor);
        $outcome = $this->analyzeStatements($statements, $scope, $state, $returnType, $class);
        $isGenerator = $this->nodes->findFirstInstanceOf($statements, Expr\Yield_::class) !== null
            || $this->nodes->findFirstInstanceOf($statements, Expr\YieldFrom::class) !== null;

        if (!$constructor && !$isGenerator && $this->requiresTermination($returnType) && $outcome->normalState !== null) {
            $this->addDiagnostic(
                DiagnosticCode::NotAllPathsReturnValue,
                sprintf(
                    'The callable may complete without returning a value compatible with %s.',
                    $returnType?->renderPhpDoc() ?? 'its declared return type',
                ),
                $declarationSpan,
                help: 'Return a compatible value on every normal path, or terminate the path with throw, exit, or a known never-returning call.',
            );
        }

        return $outcome;
    }

    /**
     * @param array<Stmt> $statements
     */
    private function analyzeStatements(
        array $statements,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        $current = $state;
        $returns = [];
        $throws = false;
        $breaks = false;
        $continues = false;
        $exits = false;
        $returnStates = [];
        $breakStates = [];

        foreach ($statements as $statement) {
            $outcome = $this->analyzeStatement($statement, $scope, $current, $returnType, $class);
            array_push($returns, ...$outcome->returns);
            array_push($returnStates, ...$outcome->returnStates);
            array_push($breakStates, ...$outcome->breakStates);
            $throws = $throws || $outcome->throws;
            $breaks = $breaks || $outcome->breaks;
            $continues = $continues || $outcome->continues;
            $exits = $exits || $outcome->exits;

            if ($outcome->normalState === null) {
                $current = null;
                break;
            }

            $current = $outcome->normalState;
        }

        return new FlowOutcome(
            $current,
            $returns,
            $throws,
            $breaks,
            $continues,
            $exits,
            $returnStates,
            $breakStates,
        );
    }

    private function analyzeStatement(
        Stmt $statement,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        if ($statement instanceof Stmt\Return_) {
            $resolution = $statement->expr === null
                ? null
                : $this->analyzeExpression($statement->expr, $scope, $state, $class);
            $this->validateReturn($statement, $resolution, $returnType);

            return new FlowOutcome(null, [$resolution], returnStates: [$state->copy()]);
        }

        if ($statement instanceof Stmt\Expression) {
            $resolution = $this->analyzeExpression($statement->expr, $scope, $state, $class);

            if ($resolution->type instanceof AtomicType && $resolution->type->canonical === 'never') {
                return new FlowOutcome(null, throws: $statement->expr instanceof Expr\Throw_, exits: true);
            }

            return FlowOutcome::normal($state);
        }

        if ($statement instanceof Stmt\If_) {
            return $this->analyzeIf($statement, $scope, $state, $returnType, $class);
        }

        if ($statement instanceof Stmt\TryCatch) {
            return $this->analyzeTry($statement, $scope, $state, $returnType, $class);
        }

        if ($statement instanceof Stmt\Switch_) {
            return $this->analyzeSwitch($statement, $scope, $state, $returnType, $class);
        }

        if ($statement instanceof Stmt\While_ || $statement instanceof Stmt\Do_
            || $statement instanceof Stmt\For_ || $statement instanceof Stmt\Foreach_) {
            return $this->analyzeLoop($statement, $scope, $state, $returnType, $class);
        }

        if ($statement instanceof Stmt\Break_) {
            return new FlowOutcome(null, breaks: true, breakStates: [$state->copy()]);
        }

        if ($statement instanceof Stmt\Continue_) {
            return new FlowOutcome(null, continues: true);
        }

        $this->analyzeNodeExpressions($statement, $scope, $state, $class);

        return FlowOutcome::normal($state);
    }

    private function analyzeIf(
        Stmt\If_ $if,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        $this->analyzeExpression($if->cond, $scope, $state, $class);
        $outcomes = [];
        $thenState = $this->narrow($if->cond, $state->copy(), true);
        $outcomes[] = $this->analyzeStatements($if->stmts, $scope, $thenState, $returnType, $class);
        $remaining = $this->narrow($if->cond, $state->copy(), false);

        foreach ($if->elseifs as $elseif) {
            $this->analyzeExpression($elseif->cond, $scope, $remaining, $class);
            $outcomes[] = $this->analyzeStatements(
                $elseif->stmts,
                $scope,
                $this->narrow($elseif->cond, $remaining->copy(), true),
                $returnType,
                $class,
            );
            $remaining = $this->narrow($elseif->cond, $remaining, false);
        }

        $outcomes[] = $if->else === null
            ? FlowOutcome::normal($remaining)
            : $this->analyzeStatements($if->else->stmts, $scope, $remaining, $returnType, $class);

        return $this->joinOutcomes($outcomes);
    }

    private function analyzeTry(
        Stmt\TryCatch $try,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        $outcomes = [$this->analyzeStatements($try->stmts, $scope, $state->copy(), $returnType, $class)];

        foreach ($try->catches as $catch) {
            $catchState = $state->copy();

            if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
                $types = array_map(
                    fn (Node\Name $name): Type => $this->sourceTypes->resolveNode(
                        $name,
                        $this->context->parsedFile,
                        $this->context->resolvedNames,
                        $this->context->genericDeclarations,
                    ),
                    $catch->types,
                );
                $catchState->recordLocal('$' . $catch->var->name, $this->combine($types));
            }

            $outcomes[] = $this->analyzeStatements($catch->stmts, $scope, $catchState, $returnType, $class);
        }

        $combined = $this->joinOutcomes($outcomes);

        if ($try->finally === null) {
            return $combined;
        }

        $finallyInputs = [...$combined->returnStates, ...$combined->breakStates];

        if ($combined->normalState !== null) {
            $finallyInputs[] = $combined->normalState;
        }

        $finallyInput = $finallyInputs === [] ? $state->copy() : FlowState::join($finallyInputs);
        $finally = $this->analyzeStatements($try->finally->stmts, $scope, $finallyInput, $returnType, $class);

        if ($finally->normalState === null) {
            return $finally;
        }

        return new FlowOutcome(
            $combined->normalState === null ? null : $finally->normalState,
            [...$combined->returns, ...$finally->returns],
            $combined->throws || $finally->throws,
            $combined->breaks || $finally->breaks,
            $combined->continues || $finally->continues,
            $combined->exits || $finally->exits,
            [
                ...($combined->returnStates === [] ? [] : [$finally->normalState]),
                ...$finally->returnStates,
            ],
            [
                ...($combined->breakStates === [] ? [] : [$finally->normalState]),
                ...$finally->breakStates,
            ],
        );
    }

    private function analyzeSwitch(
        Stmt\Switch_ $switch,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        $this->analyzeExpression($switch->cond, $scope, $state, $class);
        $hasDefault = false;
        $fallthrough = null;
        $exitStates = [];
        $returns = [];
        $returnStates = [];
        $throws = false;
        $continues = false;
        $exits = false;

        foreach ($switch->cases as $case) {
            if ($case->cond === null) {
                $hasDefault = true;
            } else {
                $this->analyzeExpression($case->cond, $scope, $state, $class);
            }

            $entryStates = [$state->copy()];

            if ($fallthrough !== null) {
                $entryStates[] = $fallthrough;
            }

            $caseOutcome = $this->analyzeStatements(
                $case->stmts,
                $scope,
                FlowState::join($entryStates),
                $returnType,
                $class,
            );
            array_push($exitStates, ...$caseOutcome->breakStates);
            array_push($returns, ...$caseOutcome->returns);
            array_push($returnStates, ...$caseOutcome->returnStates);
            $throws = $throws || $caseOutcome->throws;
            $continues = $continues || $caseOutcome->continues;
            $exits = $exits || $caseOutcome->exits;
            $fallthrough = $caseOutcome->normalState;
        }

        if ($fallthrough !== null) {
            $exitStates[] = $fallthrough;
        }

        if (!$hasDefault) {
            $exitStates[] = $state->copy();
        }

        return new FlowOutcome(
            $exitStates === [] ? null : FlowState::join($exitStates),
            $returns,
            $throws,
            false,
            $continues,
            $exits,
            $returnStates,
        );
    }

    private function analyzeLoop(
        Stmt\While_|Stmt\Do_|Stmt\For_|Stmt\Foreach_ $loop,
        Scope $scope,
        FlowState $state,
        ?Type $returnType,
        ?ClassSymbol $class,
    ): FlowOutcome {
        $body = $loop->stmts;

        if ($loop instanceof Stmt\While_ || $loop instanceof Stmt\Do_) {
            $this->analyzeExpression($loop->cond, $scope, $state, $class);
        } elseif ($loop instanceof Stmt\For_) {
            foreach ([...$loop->init, ...$loop->cond, ...$loop->loop] as $expression) {
                $this->analyzeExpression($expression, $scope, $state, $class);
            }
        } else {
            $this->analyzeExpression($loop->expr, $scope, $state, $class);
        }

        $bodyOutcome = $this->analyzeStatements($body, $scope, $state->copy(), $returnType, $class);
        $definitelyInfinite = $loop instanceof Stmt\While_
            && $loop->cond instanceof Expr\ConstFetch
            && strtolower($loop->cond->name->toString()) === 'true'
            && !$bodyOutcome->breaks;

        return new FlowOutcome(
            $definitelyInfinite ? null : $state,
            $bodyOutcome->returns,
            $bodyOutcome->throws,
            false,
            false,
            $bodyOutcome->exits,
            $bodyOutcome->returnStates,
        );
    }

    private function analyzeExpression(
        Expr $expression,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
    ): ExpressionTypeResolution {
        if ($expression instanceof Expr\Assign || $expression instanceof Expr\AssignOp) {
            $this->analyzeExpression($expression->expr, $scope, $state, $class);
            $compoundType = $expression instanceof Expr\AssignOp
                ? $this->resolveExpression($expression, $scope, $state)->type
                : null;
            $this->analyzeAssignmentTarget($expression->var, $expression->expr, $scope, $state, $class, $compoundType);
        } elseif ($expression instanceof Expr\AssignRef) {
            $this->analyzeExpression($expression->expr, $scope, $state, $class);
            $this->analyzeAssignmentTarget($expression->var, $expression->expr, $scope, $state, $class);
        } elseif ($expression instanceof Expr\FuncCall) {
            $this->analyzeFunctionCall($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
            $this->analyzeMethodCall($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\StaticCall) {
            $this->analyzeStaticCall($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\New_) {
            $this->analyzeConstructorCall($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch) {
            $this->analyzeExpression($expression->var, $scope, $state, $class);
            $this->validatePropertyRead($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\StaticPropertyFetch) {
            $this->validateStaticPropertyRead($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\ClassConstFetch) {
            $this->validateClassConstant($expression, $class);
        } elseif ($expression instanceof Expr\Closure) {
            $this->analyzeClosure($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\ArrowFunction) {
            $this->analyzeArrowFunction($expression, $scope, $state, $class);
        } elseif ($expression instanceof Expr\BinaryOp\BooleanAnd) {
            $this->analyzeExpression($expression->left, $scope, $state, $class);
            $rightState = $this->narrow($expression->left, $state->copy(), true);
            $this->analyzeExpression($expression->right, $scope, $rightState, $class);
        } elseif ($expression instanceof Expr\BinaryOp\BooleanOr) {
            $this->analyzeExpression($expression->left, $scope, $state, $class);
            $rightState = $this->narrow($expression->left, $state->copy(), false);
            $this->analyzeExpression($expression->right, $scope, $rightState, $class);
        } else {
            foreach ($expression->getSubNodeNames() as $name) {
                $value = $expression->{$name};

                if ($value instanceof Expr) {
                    $this->analyzeExpression($value, $scope, $state, $class);
                } elseif (is_array($value)) {
                    foreach ($value as $child) {
                        if ($child instanceof Expr) {
                            $this->analyzeExpression($child, $scope, $state, $class);
                        }
                    }
                }
            }
        }

        $resolution = $this->expressions->resolveDetailed($expression, $this->scopeWithState($scope, $state));
        $this->context->model->expressionTypes->record(
            $this->context->parsedFile->sourceFile,
            $expression,
            $resolution,
        );

        return $resolution;
    }

    private function analyzeClosure(Expr\Closure $closure, Scope $outer, FlowState $outerState, ?ClassSymbol $class): void
    {
        $scope = $this->createAnonymousScope($closure, $outer, $outerState, $class, $closure->static);
        $state = $this->createInitialState($scope, null, false);
        $returnType = $closure->returnType === null
            ? null
            : $this->sourceTypes->resolveNode(
                $closure->returnType,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );
        $outcome = $this->analyzeStatements($closure->stmts, $scope, $state, $returnType, $class);

        if ($this->requiresTermination($returnType) && $outcome->normalState !== null) {
            $this->addDiagnostic(
                DiagnosticCode::NotAllPathsReturnValue,
                sprintf('The closure may complete without returning %s.', $returnType?->renderPhpDoc() ?? 'a value'),
                $this->span($closure),
            );
        }
    }

    private function analyzeArrowFunction(Expr\ArrowFunction $function, Scope $outer, FlowState $outerState, ?ClassSymbol $class): void
    {
        $scope = $this->createAnonymousScope($function, $outer, $outerState, $class, $function->static);
        $state = $this->createInitialState($scope, null, false);
        $actual = $this->analyzeExpression($function->expr, $scope, $state, $class);

        if ($function->returnType === null) {
            return;
        }

        $declared = $this->sourceTypes->resolveNode(
            $function->returnType,
            $this->context->parsedFile,
            $this->context->resolvedNames,
            $this->context->genericDeclarations,
        );

        if ($this->compatibility->compare($declared, $actual->type, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
            $this->addDiagnostic(
                DiagnosticCode::ReturnTypeDoesNotMatch,
                sprintf('Arrow function result %s is not compatible with %s.', $actual->type->renderPhpDoc(), $declared->renderPhpDoc()),
                $this->span($function->expr),
            );
        }
    }

    private function analyzeFunctionCall(Expr\FuncCall $call, Scope $scope, FlowState $state, ?ClassSymbol $class): void
    {
        if (!$call->name instanceof Node\Name) {
            $this->analyzeCallArguments($call->args, $scope, $state, $class);
            return;
        }

        $resolved = $this->callables->resolveFunction($call->name);

        if ($resolved->status === CallableResolutionStatus::Missing) {
            $this->addDiagnostic(
                DiagnosticCode::FunctionDoesNotExist,
                sprintf('The function %s does not exist in the resolved project namespace.', $call->name->toString()),
                $this->span($call->name),
                help: 'Declare or import the function, or correct the resolved function name.',
            );
        } elseif ($resolved->status === CallableResolutionStatus::Found && $resolved->contract !== null) {
            $this->validateCall($call, $resolved->contract, $call->args, $scope, $state, $class);
            return;
        }

        $this->analyzeCallArguments($call->args, $scope, $state, $class);
    }

    private function analyzeMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
    ): void {
        $this->analyzeExpression($call->var, $scope, $state, $class);

        if (!$call->name instanceof Node\Identifier) {
            $this->analyzeCallArguments($call->args, $scope, $state, $class);
            return;
        }

        $receiver = $this->resolveExpression($call->var, $scope, $state)->type;
        $resolved = $this->callables->resolveMethod($receiver, $call->name->toString());

        if ($resolved->status === CallableResolutionStatus::Missing) {
            $this->addDiagnostic(
                DiagnosticCode::MethodDoesNotExist,
                sprintf('Method %s does not exist on every reachable arm of %s.', $call->name->toString(), $receiver->renderPhpDoc()),
                $this->span($call->name),
                help: 'Call a method declared by every reachable receiver type.',
            );
        } elseif ($resolved->status === CallableResolutionStatus::Found && $resolved->contract !== null) {
            if (!$this->canAccess($resolved->contract->visibility, $resolved->contract->owner, $class)) {
                $this->addDiagnostic(
                    DiagnosticCode::MemberReadIsNotAllowed,
                    sprintf('Method %s is %s and cannot be called from this scope.', $resolved->contract->identity, $resolved->contract->visibility),
                    $this->span($call->name),
                );
            }

            $this->validateCall($call, $resolved->contract, $call->args, $scope, $state, $class);
            return;
        }

        $this->analyzeCallArguments($call->args, $scope, $state, $class);
    }

    private function analyzeStaticCall(Expr\StaticCall $call, Scope $scope, FlowState $state, ?ClassSymbol $class): void
    {
        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
            $this->analyzeCallArguments($call->args, $scope, $state, $class);
            return;
        }

        $receiver = $this->resolveClassReceiver($call->class, $class);
        $resolved = $this->callables->resolveMethod($receiver, $call->name->toString());

        if ($resolved->status === CallableResolutionStatus::Missing) {
            $this->addDiagnostic(
                DiagnosticCode::MethodDoesNotExist,
                sprintf('Static method %s does not exist on %s.', $call->name->toString(), $receiver->renderPhpDoc()),
                $this->span($call->name),
            );
        } elseif ($resolved->status === CallableResolutionStatus::Found && $resolved->contract !== null) {
            if (!$resolved->contract->static
                && !in_array(strtolower($call->class->toString()), ['self', 'parent', 'static'], true)) {
                $this->addDiagnostic(
                    DiagnosticCode::StaticMemberAccessIsInvalid,
                    sprintf('%s is an instance method and cannot be called statically.', $resolved->contract->identity),
                    $this->span($call),
                    related: $this->contractLabel($resolved->contract, 'The instance method is declared here.'),
                );
            }

            $this->validateCall($call, $resolved->contract, $call->args, $scope, $state, $class);
            return;
        }

        $this->analyzeCallArguments($call->args, $scope, $state, $class);
    }

    private function analyzeConstructorCall(Expr\New_ $new, Scope $scope, FlowState $state, ?ClassSymbol $class): void
    {
        if (!$new->class instanceof Node\Name) {
            $this->analyzeCallArguments($new->args, $scope, $state, $class);
            return;
        }

        $resolved = $this->callables->resolveConstructor($this->resolveClassReceiver($new->class, $class));

        if ($resolved->status === CallableResolutionStatus::Found && $resolved->contract !== null) {
            $this->validateCall($new, $resolved->contract, $new->args, $scope, $state, $class);
            return;
        }

        $this->analyzeCallArguments($new->args, $scope, $state, $class);
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $arguments
     */
    private function validateCall(
        Expr $call,
        CallableContract $contract,
        array $arguments,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
    ): void {
        $binding = $this->argumentBinder->bind($contract, $arguments);

        foreach ($binding->issues as $issue) {
            $this->reportBindingIssue($issue, $call, $contract);
        }

        $constraints = [];
        $actualTypes = [];

        foreach ($binding->arguments as $bound) {
            $actual = $this->analyzeExpression($bound->argument->value, $scope, $state, $class)->type;
            $actualTypes[spl_object_id($bound->argument)] = $actual;
            $parameterType = $bound->parameter->effectiveType();

            if ($parameterType !== null) {
                $constraints[] = [
                    'parameter' => (new TypeSubstitution($contract->receiverSubstitutions))->substitute($parameterType),
                    'actual' => $actual,
                ];
            }

            if ($bound->parameter->byReference && !$this->isReferenceable($bound->argument->value)) {
                $this->addDiagnostic(
                    DiagnosticCode::ArgumentMustBeReferenceable,
                    sprintf('Argument for by-reference parameter %s must be a variable or writable location.', $bound->parameter->name),
                    $this->span($bound->argument->value),
                    related: $contract->origin->value === 'intrinsic'
                        ? []
                        : [new DiagnosticLabel($bound->parameter->declarationSpan, 'The by-reference parameter is declared here.')],
                );
            }
        }

        $inference = $this->genericInference->infer($contract, $constraints, symbols: $this->context->symbols);
        $substitution = new TypeSubstitution($inference->substitutions);

        foreach ($binding->arguments as $bound) {
            $parameterType = $bound->parameter->effectiveType();

            if ($parameterType === null) {
                $this->invalidateByReferenceLocal($bound, new AtomicType('mixed'), $state);
                continue;
            }

            $expected = $substitution->substitute($parameterType);
            $actual = $actualTypes[spl_object_id($bound->argument)];

            if ($this->compatibility->compare($expected, $actual, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
                $related = $contract->origin->value === 'intrinsic'
                    ? []
                    : [new DiagnosticLabel($bound->parameter->declarationSpan, sprintf('Parameter %s is declared as %s.', $bound->parameter->name, $expected->renderPhpDoc()))];
                $this->addDiagnostic(
                    $this->mismatchCode($actual, DiagnosticCode::ArgumentTypeDoesNotMatch),
                    sprintf(
                        'Argument for %s expects %s, but %s was provided.',
                        $bound->parameter->name,
                        $expected->renderPhpDoc(),
                        $actual->renderPhpDoc(),
                    ),
                    $this->span($bound->argument->value),
                    related: $related,
                    help: 'Pass a value compatible with the declared parameter type.',
                );
            }

            $this->invalidateByReferenceLocal($bound, $expected, $state);
        }

        if ($call instanceof Expr\MethodCall && $call->var instanceof Expr\Variable && $call->var->name === 'this') {
            foreach ($this->resolveHelperInitializations($contract, $class) as $property) {
                $state->recordPropertyInitialization($property);
            }
        }
    }

    private function invalidateByReferenceLocal(
        BoundCallArgument $bound,
        Type $parameterType,
        FlowState $state,
    ): void {
        $value = $bound->argument->value;

        if ($bound->parameter->byReference && $value instanceof Expr\Variable && is_string($value->name)) {
            $state->recordLocal('$' . $value->name, $parameterType);
        }
    }

    /** @param array<Arg|Node\VariadicPlaceholder> $arguments */
    private function analyzeCallArguments(array $arguments, Scope $scope, FlowState $state, ?ClassSymbol $class): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Arg) {
                $this->analyzeExpression($argument->value, $scope, $state, $class);
            }
        }
    }

    private function analyzeAssignmentTarget(
        Expr $target,
        Expr $value,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
        ?Type $actualOverride = null,
    ): void {
        $actual = $actualOverride ?? $this->resolveExpression($value, $scope, $state)->type;

        if ($target instanceof Expr\ArrayDimFetch) {
            $container = $this->resolveExpression($target->var, $scope, $state)->type;
            $propertyContainer = $target->var instanceof Expr\PropertyFetch
                || $target->var instanceof Expr\NullsafePropertyFetch;

            if ($propertyContainer && $container instanceof TypedArrayType) {
                if ($this->compatibility->compare($container->valueType, $actual, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
                    $this->addDiagnostic(
                        DiagnosticCode::TypedArrayValueTypeDoesNotMatch,
                        sprintf('Array element expects %s, but %s was assigned.', $container->valueType->renderPhpDoc(), $actual->renderPhpDoc()),
                        $this->span($value),
                    );
                }

                if ($target->dim !== null) {
                    $key = $this->resolveExpression($target->dim, $scope, $state)->type;

                    if ($this->compatibility->compare($container->keyType, $key, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
                        $this->addDiagnostic(
                            DiagnosticCode::TypedArrayKeyTypeDoesNotMatch,
                            sprintf('Array key expects %s, but %s was used.', $container->keyType->renderPhpDoc(), $key->renderPhpDoc()),
                            $this->span($target->dim),
                        );
                    }
                }
            }

            if ($propertyContainer) {
                $propertyReceiver = $this->resolveExpression($target->var->var, $scope, $state)->type;
                $propertyResolution = $target->var->name instanceof Node\Identifier
                    ? $this->members->resolveProperty($propertyReceiver, $target->var->name->toString())
                    : new MemberResolution([], false, MemberResolutionStatus::UnknownReceiver);
                $this->validatePropertyResolution($target->var, $propertyReceiver, $propertyResolution, true, $class);
            }

            return;
        }

        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $name = '$' . $target->name;
            $declared = $this->typedLocals[$this->span($target)->start->offset] ?? $scope->resolve($name)?->type->semanticType;
            $state->recordLocal($name, $declared ?? $actual);
            return;
        }

        if ((($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch)
                && $target->name instanceof Node\Identifier)
            || ($target instanceof Expr\StaticPropertyFetch
                && $target->class instanceof Node\Name
                && $target->name instanceof Node\VarLikeIdentifier)) {
            $static = $target instanceof Expr\StaticPropertyFetch;
            $receiver = $static
                ? $this->resolveClassReceiver($target->class, $class)
                : $this->resolveExpression($target->var, $scope, $state)->type;
            $resolution = $this->members->resolveProperty($receiver, $target->name->toString());

            if (!$this->validatePropertyResolution($target, $receiver, $resolution, true, $class)) {
                return;
            }

            foreach ($resolution->targets as $resolvedTarget) {
                $property = $resolvedTarget['member'];

                if (!$property instanceof PropertySymbol) {
                    continue;
                }

                $expected = $property->effectiveType();

                if ($expected !== null) {
                    $expected = $this->members->resolveTargetType(
                        $expected,
                        $resolvedTarget['receiver'],
                        $resolvedTarget['substitutions'],
                        $resolvedTarget['calledReceiver'],
                    );

                    if ($this->compatibility->compare($expected, $actual, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
                        $this->addDiagnostic(
                            $this->mismatchCode($actual, DiagnosticCode::PropertyTypeDoesNotMatch),
                            sprintf('Property %s expects %s, but %s was assigned.', $property->name, $expected->renderPhpDoc(), $actual->renderPhpDoc()),
                            $this->span($value),
                            related: [new DiagnosticLabel($property->declarationSpan, 'The property is declared here.')],
                        );
                    }
                }

                if (!$static && $target->var instanceof Expr\Variable && $target->var->name === 'this') {
                    $state->recordPropertyInitialization($property->name);
                }
            }
        }
    }

    private function validatePropertyRead(
        Expr\PropertyFetch|Expr\NullsafePropertyFetch $fetch,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
    ): void {
        if (!$fetch->name instanceof Node\Identifier) {
            return;
        }

        $receiver = $this->resolveExpression($fetch->var, $scope, $state)->type;
        $resolution = $this->members->resolveProperty($receiver, $fetch->name->toString());
        $this->validatePropertyResolution($fetch, $receiver, $resolution, false, $class);
    }

    private function validateStaticPropertyRead(
        Expr\StaticPropertyFetch $fetch,
        Scope $scope,
        FlowState $state,
        ?ClassSymbol $class,
    ): void {
        if (!$fetch->class instanceof Node\Name || !$fetch->name instanceof Node\VarLikeIdentifier) {
            if ($fetch->class instanceof Expr) {
                $this->analyzeExpression($fetch->class, $scope, $state, $class);
            }
            return;
        }

        $receiver = $this->resolveClassReceiver($fetch->class, $class);
        $resolution = $this->members->resolveProperty($receiver, $fetch->name->toString());
        $this->validatePropertyResolution($fetch, $receiver, $resolution, false, $class);
    }

    private function validateClassConstant(Expr\ClassConstFetch $fetch, ?ClassSymbol $current): void
    {
        if (!$fetch->class instanceof Node\Name || !$fetch->name instanceof Node\Identifier
            || strtolower($fetch->name->toString()) === 'class') {
            return;
        }

        $receiver = $this->resolveClassReceiver($fetch->class, $current);
        $className = $receiver instanceof GenericType
            ? $receiver->base->name
            : ($receiver instanceof AtomicType && !$receiver->isBuiltin ? $receiver->name : null);
        $constant = $className === null ? null : $this->findClassConstant($className, $fetch->name->toString());

        if ($constant === null) {
            $knownClass = $className === null ? null : $this->context->symbols->findClass($className);

            if ($knownClass !== null && !$this->classHierarchyIsDeferred($knownClass)) {
                $this->addDiagnostic(
                    DiagnosticCode::ClassConstantDoesNotExist,
                    sprintf('Class constant or enum case %s::%s does not exist.', $className, $fetch->name->toString()),
                    $this->span($fetch->name),
                );
            }
            return;
        }

        if (!$this->canAccess($constant->visibility, $constant->declaringClass, $current)) {
            $this->addDiagnostic(
                DiagnosticCode::MemberReadIsNotAllowed,
                sprintf('Class constant %s::%s is %s and cannot be read from this scope.', $constant->declaringClass, $constant->name, $constant->visibility),
                $this->span($fetch),
                related: [new DiagnosticLabel($constant->declarationSpan, 'The class constant is declared here.')],
            );
        }
    }

    private function validatePropertyResolution(
        Expr\PropertyFetch|Expr\NullsafePropertyFetch|Expr\StaticPropertyFetch $fetch,
        Type $receiver,
        MemberResolution $resolution,
        bool $write,
        ?ClassSymbol $class,
    ): bool {
        if ($resolution->status === MemberResolutionStatus::Missing) {
            if ($write && !$fetch instanceof Expr\StaticPropertyFetch) {
                return false;
            }

            $memberName = $fetch->name instanceof Expr ? '<dynamic>' : $fetch->name->toString();
            $this->addDiagnostic(
                DiagnosticCode::PropertyDoesNotExist,
                sprintf('Property %s does not exist on every reachable arm of %s.', $memberName, $receiver->renderPhpDoc()),
                $this->span($fetch->name),
            );
            return false;
        }

        if ($resolution->status !== MemberResolutionStatus::Found) {
            return false;
        }

        foreach ($resolution->targets as $target) {
            $property = $target['member'];

            if (!$property instanceof PropertySymbol) {
                continue;
            }

            $staticFetch = $fetch instanceof Expr\StaticPropertyFetch;

            if ($staticFetch !== $property->static) {
                $this->addDiagnostic(
                    $staticFetch ? DiagnosticCode::StaticMemberAccessIsInvalid : DiagnosticCode::InstanceMemberAccessIsInvalid,
                    sprintf(
                        'Property %s::$%s is %s and cannot be accessed through %s syntax.',
                        $property->declaringClass,
                        $property->name,
                        $property->static ? 'static' : 'an instance property',
                        $staticFetch ? 'static' : 'instance',
                    ),
                    $this->span($fetch),
                    related: [new DiagnosticLabel($property->declarationSpan, 'The property is declared here.')],
                );
                return false;
            }

            $visibility = $write ? $property->effectiveWriteVisibility() : $property->visibility;

            if (!$this->canAccess($visibility, $property->declaringClass, $class)
                || ($write && ($property->readonly && $class?->fullyQualifiedName !== $property->declaringClass))
                || ($write && $property->hasGetter && !$property->hasSetter && $property->virtual)) {
                $this->addDiagnostic(
                    $write ? DiagnosticCode::MemberWriteIsNotAllowed : DiagnosticCode::MemberReadIsNotAllowed,
                    sprintf(
                        'Property %s exists but cannot be %s from this scope.',
                        $property->name,
                        $write ? 'written' : 'read',
                    ),
                    $this->span($fetch),
                    related: [new DiagnosticLabel($property->declarationSpan, 'The property access contract is declared here.')],
                );
                return false;
            }
        }

        return true;
    }

    private function validateReturn(Stmt\Return_ $return, ?ExpressionTypeResolution $actual, ?Type $declared): void
    {
        if ($declared === null) {
            return;
        }

        $isVoid = $declared instanceof AtomicType && $declared->canonical === 'void';
        $isNever = $declared instanceof AtomicType && $declared->canonical === 'never';

        if ($actual === null) {
            if (!$isVoid) {
                $this->addDiagnostic(
                    DiagnosticCode::ReturnTypeDoesNotMatch,
                    sprintf('A bare return is not compatible with declared return type %s.', $declared->renderPhpDoc()),
                    $this->span($return),
                );
            }
            return;
        }

        if ($isVoid || $isNever
            || $this->compatibility->compare($declared, $actual->type, $this->context->symbols) === TypeCompatibilityResult::Incompatible) {
            $this->addDiagnostic(
                $this->returnMismatchCode($declared, $actual->type),
                sprintf('Returned type %s is not compatible with declared return type %s.', $actual->type->renderPhpDoc(), $declared->renderPhpDoc()),
                $this->span($return->expr ?? $return),
                help: 'Return a value compatible with the callable return type.',
            );
        }
    }

    private function checkPropertyInitialization(
        ClassSymbol $class,
        ?MethodSymbol $constructor,
        ?FlowOutcome $outcome,
    ): void {
        if ($class->kind !== 'class' || $class->abstract) {
            return;
        }

        foreach ($class->properties as $property) {
            if ($property->static || !$property->hasBackingStorage || $property->hasDefault || $property->promoted
                || $property->effectiveType() === null) {
                continue;
            }

            $completionState = $outcome === null ? null : $this->resolveNormalCompletionState($outcome);
            $initialized = $outcome !== null
                && ($completionState === null || $completionState->isPropertyInitialized($property->name));

            if (!$initialized) {
                $related = $constructor === null
                    ? []
                    : [new DiagnosticLabel($constructor->declarationSpan, 'This constructor may complete without initializing the property.')];
                $this->addDiagnostic(
                    DiagnosticCode::PropertyMayBeUninitialized,
                    sprintf('Backed property %s::$%s may be uninitialized after construction.', $class->fullyQualifiedName, $property->name),
                    $property->selectionSpan,
                    related: $related,
                    help: 'Provide a default or assign the property on every normally completing constructor path.',
                );
            }
        }
    }

    /** @return list<string> */
    private function resolveHelperInitializations(CallableContract $contract, ?ClassSymbol $class): array
    {
        if ($class === null || !$contract->sourceSymbol instanceof MethodSymbol
            || !($contract->sourceSymbol->visibility === 'private' || $contract->sourceSymbol->final || $class->final)) {
            return [];
        }

        $key = strtolower($contract->identity);

        if (isset($this->helperInitializations[$key])) {
            return array_keys($this->helperInitializations[$key]);
        }

        if (isset($this->activeHelpers[$key])) {
            return [];
        }

        $node = $this->findMethodNode($contract->sourceSymbol);

        if ($node === null || $node->stmts === null) {
            return [];
        }

        $this->activeHelpers[$key] = true;
        $scope = $this->createCallableScope($node, $contract->parameters, $class, false);
        $outcome = $this->analyzeStatements($node->stmts, $scope, $this->createInitialState($scope, $class, false), $contract->returnType, $class);
        unset($this->activeHelpers[$key]);
        $completionState = $this->resolveNormalCompletionState($outcome);
        $properties = $completionState === null
            ? []
            : array_fill_keys($completionState->initializedPropertyNames, true);

        return array_keys($this->helperInitializations[$key] = $properties);
    }

    private function narrow(Expr $condition, FlowState $state, bool $positive): FlowState
    {
        if ($condition instanceof Expr\BinaryOp\BooleanAnd && $positive) {
            return $this->narrow($condition->right, $this->narrow($condition->left, $state, true), true);
        }

        if ($condition instanceof Expr\BinaryOp\BooleanOr && !$positive) {
            return $this->narrow($condition->right, $this->narrow($condition->left, $state, false), false);
        }

        if (($condition instanceof Expr\BinaryOp\Identical || $condition instanceof Expr\BinaryOp\NotIdentical)
            && $condition->left instanceof Expr\Variable
            && is_string($condition->left->name)
            && $condition->right instanceof Expr\ConstFetch
            && strtolower($condition->right->name->toString()) === 'null') {
            $isNull = $condition instanceof Expr\BinaryOp\Identical ? $positive : !$positive;
            $this->narrowLocal($state, '$' . $condition->left->name, 'null', $isNull);
            return $state;
        }

        if ($condition instanceof Expr\FuncCall && $condition->name instanceof Node\Name
            && isset($condition->args[0]) && $condition->args[0] instanceof Arg
            && $condition->args[0]->value instanceof Expr\Variable
            && is_string($condition->args[0]->value->name)) {
            $type = match (strtolower($condition->name->toString())) {
                'is_null' => 'null',
                'is_int' => 'int',
                'is_string' => 'string',
                'is_bool' => 'bool',
                'is_float' => 'float',
                'is_array' => 'array',
                'is_object' => 'object',
                'is_callable' => 'callable',
                default => null,
            };

            if ($type !== null) {
                $this->narrowLocal($state, '$' . $condition->args[0]->value->name, $type, $positive);
            }

            return $state;
        }

        if ($condition instanceof Expr\Isset_ && isset($condition->vars[0])
            && $condition->vars[0] instanceof Expr\Variable && is_string($condition->vars[0]->name)) {
            $this->narrowLocal($state, '$' . $condition->vars[0]->name, 'null', !$positive);
            return $state;
        }

        if ($condition instanceof Expr\Instanceof_ && $condition->expr instanceof Expr\Variable
            && is_string($condition->expr->name) && $condition->class instanceof Node\Name && $positive) {
            $state->recordLocal(
                '$' . $condition->expr->name,
                $this->resolveClassReceiver($condition->class, null),
            );
        }

        return $state;
    }

    private function narrowLocal(FlowState $state, string $name, string $typeName, bool $keep): void
    {
        $type = $state->resolveLocal($name);

        if ($type === null) {
            return;
        }

        if ($keep) {
            $state->recordLocal($name, new AtomicType($typeName));
            return;
        }

        if ($type instanceof UnionType) {
            $remaining = array_values(array_filter(
                $type->members,
                static fn (Type $member): bool => $member->canonical !== strtolower($typeName),
            ));

            if ($remaining !== []) {
                $state->recordLocal($name, $this->combine($remaining));
            }
        }
    }

    /** @param list<FlowOutcome> $outcomes */
    private function joinOutcomes(array $outcomes): FlowOutcome
    {
        $states = [];
        $returns = [];
        $throws = false;
        $breaks = false;
        $continues = false;
        $exits = false;
        $returnStates = [];
        $breakStates = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->normalState !== null) {
                $states[] = $outcome->normalState;
            }

            array_push($returns, ...$outcome->returns);
            array_push($returnStates, ...$outcome->returnStates);
            array_push($breakStates, ...$outcome->breakStates);
            $throws = $throws || $outcome->throws;
            $breaks = $breaks || $outcome->breaks;
            $continues = $continues || $outcome->continues;
            $exits = $exits || $outcome->exits;
        }

        return new FlowOutcome(
            $states === [] ? null : FlowState::join($states),
            $returns,
            $throws,
            $breaks,
            $continues,
            $exits,
            $returnStates,
            $breakStates,
        );
    }

    private function resolveNormalCompletionState(FlowOutcome $outcome): ?FlowState
    {
        $states = $outcome->returnStates;

        if ($outcome->normalState !== null) {
            $states[] = $outcome->normalState;
        }

        return $states === [] ? null : FlowState::join($states);
    }

    /**
     * @param Node\FunctionLike $callable
     * @param list<\Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol> $parameters
     */
    private function createCallableScope(Node\FunctionLike $callable, array $parameters, ?ClassSymbol $class, bool $static): Scope
    {
        $scope = new Scope('type-flow');

        if (!$static && $class !== null) {
            $classParameters = $class->genericDeclaration === null
                ? []
                : $class->genericDeclaration->parameters;
            $self = $classParameters === []
                ? new AtomicType($class->fullyQualifiedName)
                : new GenericType(new AtomicType($class->fullyQualifiedName), $classParameters);
            $scope->declare(new VariableSymbol('$this', LocalType::createFromSemanticType($self), BindingMutability::Mutable));
        }

        foreach ($parameters as $parameter) {
            $type = $parameter->effectiveType();
            $scope->declare(new VariableSymbol(
                $parameter->name,
                $type === null ? LocalType::createUnknown() : LocalType::createFromSemanticType($type),
                BindingMutability::Mutable,
                $parameter->declarationSpan,
            ));
        }

        $span = $this->span($callable);

        foreach ($this->context->model->bindings->bindings as $binding) {
            if ($binding->declarationSpan->start->offset < $span->start->offset
                || $binding->declarationSpan->end->offset > $span->end->offset) {
                continue;
            }

            $scope->declare(new VariableSymbol(
                $binding->name,
                $binding->type,
                $binding->mutability,
                $binding->declarationSpan,
                $binding,
            ));
        }

        return $scope;
    }

    private function createAnonymousScope(
        Expr\Closure|Expr\ArrowFunction $callable,
        Scope $outer,
        FlowState $outerState,
        ?ClassSymbol $class,
        bool $static,
    ): Scope {
        $scope = new Scope('anonymous-type-flow');
        $captured = [];

        if ($callable instanceof Expr\ArrowFunction) {
            $captured = $outer->symbols;
        } else {
            foreach ($callable->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }

                $name = '$' . $use->var->name;
                $symbol = $outer->resolve($name);

                if ($symbol !== null) {
                    $captured[$name] = $symbol;
                }
            }

            $thisSymbol = $outer->resolve('$this');

            if ($thisSymbol !== null) {
                $captured['$this'] = $thisSymbol;
            }
        }

        foreach ($captured as $symbol) {
            if ($static && $symbol->name === '$this') {
                continue;
            }

            $flowType = $outerState->resolveLocal($symbol->name);
            $scope->declare(new VariableSymbol(
                $symbol->name,
                $flowType === null ? $symbol->type : LocalType::createFromSemanticType($flowType),
                $symbol->mutability,
                $symbol->declarationSpan,
                $symbol->binding,
            ));
        }

        foreach ($callable->getParams() as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $type = $parameter->type === null
                ? new AtomicType('mixed')
                : $this->sourceTypes->resolveNode(
                    $parameter->type,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                );
            $scope->declare(new VariableSymbol(
                '$' . $parameter->var->name,
                LocalType::createFromSemanticType($type),
                BindingMutability::Mutable,
                $this->span($parameter),
            ));
        }

        return $scope;
    }

    private function createInitialState(Scope $scope, ?ClassSymbol $class, bool $constructor): FlowState
    {
        $state = new FlowState();

        foreach ($scope->symbols as $symbol) {
            $state->recordLocal($symbol->name, $symbol->type->semanticType);
        }

        if ($constructor && $class !== null) {
            foreach ($class->properties as $property) {
                if ($property->hasDefault || $property->promoted) {
                    $state->recordPropertyInitialization($property->name);
                }
            }
        }

        return $state;
    }

    private function scopeWithState(Scope $base, FlowState $state): Scope
    {
        $scope = new Scope($base->kind);

        foreach ($state->locals as $name => $type) {
            $baseSymbol = $base->resolve($name);
            $mutability = $baseSymbol === null ? BindingMutability::Mutable : $baseSymbol->mutability;
            $declarationSpan = $baseSymbol === null ? null : $baseSymbol->declarationSpan;
            $binding = $baseSymbol === null ? null : $baseSymbol->binding;
            $scope->declare(new VariableSymbol(
                $name,
                LocalType::createFromSemanticType($type),
                $mutability,
                $declarationSpan,
                $binding,
            ));
        }

        foreach ($base->symbols as $symbol) {
            $scope->declare($symbol);
        }

        return $scope;
    }

    private function resolveExpression(Expr $expression, Scope $scope, FlowState $state): ExpressionTypeResolution
    {
        return $this->expressions->resolveDetailed($expression, $this->scopeWithState($scope, $state));
    }

    private function resolveClassReceiver(Node\Name $name, ?ClassSymbol $class): Type
    {
        $lower = strtolower($name->toString());

        if ($class !== null && in_array($lower, ['self', 'static'], true)) {
            return new AtomicType($class->fullyQualifiedName);
        }

        if ($class !== null && $lower === 'parent' && $class->parent !== null) {
            return new AtomicType($class->parent);
        }

        return $this->sourceTypes->resolveNode(
            $name,
            $this->context->parsedFile,
            $this->context->resolvedNames,
            $this->context->genericDeclarations,
        );
    }

    /** @param array<Type> $types */
    private function combine(array $types): Type
    {
        $unique = [];

        foreach ($types as $type) {
            $unique[$type->canonical] = $type;
        }

        return match (count($unique)) {
            0 => new AtomicType('mixed'),
            1 => array_values($unique)[0],
            default => new UnionType(array_values($unique)),
        };
    }

    private function requiresTermination(?Type $returnType): bool
    {
        return $returnType !== null
            && !($returnType instanceof AtomicType && in_array($returnType->canonical, ['void', 'mixed'], true));
    }

    private function isReferenceable(Expr $expression): bool
    {
        return $expression instanceof Expr\Variable
            || $expression instanceof Expr\ArrayDimFetch
            || $expression instanceof Expr\PropertyFetch
            || $expression instanceof Expr\StaticPropertyFetch;
    }

    private function canAccess(string $visibility, ?string $owner, ?ClassSymbol $current): bool
    {
        if ($visibility === 'public') {
            return true;
        }

        if ($owner === null || $current === null) {
            return false;
        }

        if (strcasecmp($owner, $current->fullyQualifiedName) === 0) {
            return true;
        }

        return $visibility === 'protected'
            && ($this->isSubclassOf($current, $owner) || $this->isSubclassOfName($owner, $current->fullyQualifiedName));
    }

    private function isSubclassOf(ClassSymbol $class, string $parent): bool
    {
        return $this->isSubclassOfName($class->fullyQualifiedName, $parent);
    }

    private function isSubclassOfName(string $className, string $parent): bool
    {
        $visited = [];
        $class = $this->context->symbols->findClass($className);

        while ($class !== null && $class->parent !== null) {
            $key = strtolower($class->fullyQualifiedName);

            if (isset($visited[$key])) {
                return false;
            }

            $visited[$key] = true;

            if (strcasecmp($class->parent, $parent) === 0) {
                return true;
            }

            $class = $this->context->symbols->findClass($class->parent);
        }

        return false;
    }

    private function findClassConstant(string $className, string $name): ?ClassConstantSymbol
    {
        $visited = [];

        while (($class = $this->context->symbols->findClass($className)) !== null) {
            $key = strtolower($class->fullyQualifiedName);

            if (isset($visited[$key])) {
                return null;
            }

            $visited[$key] = true;
            $constant = $class->findConstant($name);

            if ($constant !== null) {
                return $constant;
            }

            if ($class->parent === null) {
                return null;
            }

            $className = $class->parent;
        }

        return null;
    }

    /** @param array<string, true> $visited */
    private function classHierarchyIsDeferred(ClassSymbol $class, array &$visited = []): bool
    {
        $key = strtolower($class->fullyQualifiedName);

        if (isset($visited[$key])) {
            return false;
        }

        $visited[$key] = true;

        foreach ([...$class->interfaces, ...$class->traits, ...($class->parent === null ? [] : [$class->parent])] as $related) {
            $symbol = $this->context->symbols->findClass($related);

            if ($symbol === null || $this->classHierarchyIsDeferred($symbol, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function reportBindingIssue(CallBindingIssue $issue, Expr $call, CallableContract $contract): void
    {
        $code = match ($issue->kind) {
            CallBindingIssueKind::ArgumentCount => DiagnosticCode::ArgumentCountDoesNotMatch,
            CallBindingIssueKind::UnknownNamedArgument => DiagnosticCode::NamedArgumentDoesNotExist,
            CallBindingIssueKind::DuplicateNamedArgument => DiagnosticCode::DuplicateNamedArgument,
            CallBindingIssueKind::PositionalAfterNamed => DiagnosticCode::PositionalArgumentAfterNamedArgument,
        };
        $this->addDiagnostic(
            $code,
            $issue->message,
            $this->span($issue->argument ?? $call),
            related: $this->contractLabel($contract, 'The callable contract is declared here.'),
        );
    }

    /** @return list<DiagnosticLabel> */
    private function contractLabel(CallableContract $contract, string $message): array
    {
        return $contract->declarationSpan === null ? [] : [new DiagnosticLabel($contract->declarationSpan, $message)];
    }

    private function findFunction(Stmt\Function_ $node): ?FunctionSymbol
    {
        $offset = $this->span($node->name)->start->offset;

        foreach ($this->context->symbols->functions as $function) {
            if ($function->sourceFile === $this->context->parsedFile->sourceFile
                && $function->selectionSpan->start->offset === $offset) {
                return $function;
            }
        }

        return null;
    }

    private function findClass(Stmt\ClassLike $node): ?ClassSymbol
    {
        if ($node->name === null) {
            return null;
        }

        $offset = $this->span($node->name)->start->offset;

        foreach ($this->context->symbols->classes as $class) {
            if ($class->sourceFile === $this->context->parsedFile->sourceFile
                && $class->selectionSpan->start->offset === $offset) {
                return $class;
            }
        }

        return null;
    }

    private function findMethodNode(MethodSymbol $method): ?Stmt\ClassMethod
    {
        foreach ($this->nodes->findInstanceOf($this->context->parsedFile->statements, Stmt\ClassMethod::class) as $node) {
            if ($this->span($node->name)->start->offset === $method->selectionSpan->start->offset) {
                return $node;
            }
        }

        return null;
    }

    private function analyzeNodeExpressions(Node $node, Scope $scope, FlowState $state, ?ClassSymbol $class): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Expr) {
                $this->analyzeExpression($value, $scope, $state, $class);
            } elseif ($value instanceof Node) {
                $this->analyzeNodeExpressions($value, $scope, $state, $class);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Expr) {
                        $this->analyzeExpression($child, $scope, $state, $class);
                    } elseif ($child instanceof Node) {
                        $this->analyzeNodeExpressions($child, $scope, $state, $class);
                    }
                }
            }
        }
    }

    private function span(Node $node): Span
    {
        return $this->spans->resolve($this->context->parsedFile, $node);
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        array $related = [],
        ?string $help = null,
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
            $help,
        ));
    }

    /** @param array<Stmt> $statements */
    private function checkTypeNames(array $statements): void
    {
        $nonTypes = [];

        foreach ($this->nodes->findInstanceOf($statements, Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name) {
                $nonTypes[spl_object_id($call->name)] = true;
            }
        }

        foreach ($this->nodes->findInstanceOf($statements, Expr\ConstFetch::class) as $fetch) {
            $nonTypes[spl_object_id($fetch->name)] = true;
        }

        foreach ($this->nodes->findInstanceOf($statements, Stmt\Namespace_::class) as $namespace) {
            if ($namespace->name !== null) {
                $nonTypes[spl_object_id($namespace->name)] = true;
            }
        }

        foreach ($this->nodes->findInstanceOf($statements, Stmt\Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $nonTypes[spl_object_id($item->name)] = true;
            }
        }

        foreach ($this->nodes->findInstanceOf($statements, Stmt\GroupUse::class) as $use) {
            $nonTypes[spl_object_id($use->prefix)] = true;

            foreach ($use->uses as $item) {
                $nonTypes[spl_object_id($item->name)] = true;
            }
        }

        foreach ($this->nodes->findInstanceOf($statements, Node\Attribute::class) as $attribute) {
            $nonTypes[spl_object_id($attribute->name)] = true;
        }

        foreach ($this->nodes->findInstanceOf($statements, Node\Name::class) as $name) {
            if (isset($nonTypes[spl_object_id($name)])) {
                continue;
            }

            $raw = strtolower($name->toString());

            if (in_array($raw, ['self', 'static', 'parent'], true)) {
                continue;
            }

            $type = $this->sourceTypes->resolveNode(
                $name,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );

            if ($type instanceof TypeParameter) {
                continue;
            }

            $resolved = $this->context->resolvedNames->resolve($name) ?? $name->toString();

            if ($this->context->symbols->findClass($resolved) !== null || $this->coreTypes->contains($resolved)) {
                continue;
            }

            if ($name->isFullyQualified() && !$this->belongsToProjectNamespace($resolved)) {
                continue;
            }

            if (!$this->belongsToProjectNamespace($resolved)) {
                continue;
            }

            $this->addDiagnostic(
                DiagnosticCode::TypeDoesNotExist,
                sprintf('Type %s does not exist in the compiler-owned project declaration context.', $resolved),
                $this->span($name),
                help: 'Declare or import the type, or configure a stub. Unindexed external dependency types remain deferred.',
            );
        }
    }

    private function belongsToProjectNamespace(string $name): bool
    {
        $separator = strrpos($name, '\\');
        $namespace = $separator === false ? '' : substr($name, 0, $separator);

        if ($namespace === '') {
            return false;
        }

        foreach ([...$this->context->symbols->classes, ...$this->context->symbols->functions] as $symbol) {
            if (strcasecmp($symbol->namespace, $namespace) === 0) {
                return true;
            }
        }

        return false;
    }

    private function mismatchCode(Type $actual, DiagnosticCode $fallback): DiagnosticCode
    {
        return $actual instanceof AtomicType && $actual->canonical === 'null'
            ? DiagnosticCode::NullNotAssignable
            : $fallback;
    }

    private function returnMismatchCode(Type $declared, Type $actual): DiagnosticCode
    {
        if (!$declared instanceof TypedArrayType || !$actual instanceof TypedArrayType) {
            return $this->mismatchCode($actual, DiagnosticCode::ReturnTypeDoesNotMatch);
        }

        if ($declared->isList && !$actual->isList) {
            return DiagnosticCode::OperationWouldBreakListShape;
        }

        if ($this->compatibility->compare($declared->keyType, $actual->keyType, $this->context->symbols)
            === TypeCompatibilityResult::Incompatible) {
            return DiagnosticCode::TypedArrayKeyTypeDoesNotMatch;
        }

        return DiagnosticCode::TypedArrayValueTypeDoesNotMatch;
    }
}
