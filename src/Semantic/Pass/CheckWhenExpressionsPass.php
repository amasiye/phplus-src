<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Amasiye\Ppphp\Frontend\Ast\TypedForeachBinding;
use Amasiye\Ppphp\Frontend\Ast\WhenBranch;
use Amasiye\Ppphp\Frontend\Ast\WhenElseBranch;
use Amasiye\Ppphp\Frontend\Ast\WhenExpression;
use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Amasiye\Ppphp\Semantic\Binding\LocalBinding;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\Scope\Scope;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use Amasiye\Ppphp\Semantic\Symbol\VariableSymbol;
use Amasiye\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibility;
use Amasiye\Ppphp\Semantic\Type\TypeResolver;
use Amasiye\Ppphp\Semantic\Type\TypedArrayType;
use Amasiye\Ppphp\Semantic\Type\UnionType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\When\WhenBranchAnalysis;
use Amasiye\Ppphp\Semantic\When\WhenExpressionAnalysis;
use Amasiye\Ppphp\Semantic\When\WhenExpressionLocation;
use Amasiye\Ppphp\Semantic\When\WhenExpressionSite;
use Amasiye\Ppphp\Semantic\When\WhenFragmentParser;
use Amasiye\Ppphp\Semantic\When\WhenParsedBranch;
use Amasiye\Ppphp\Semantic\When\WhenParsedExpression;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final class CheckWhenExpressionsPass implements SemanticPass
{
    private SemanticContext $context;

    /** @var array<string, WhenParsedExpression> */
    private array $parsed = [];

    /** @var array<string, WhenExpressionLocation> */
    private array $locations = [];

    /** @var array<int, TypedLocalDeclaration> */
    private array $typedLocals = [];

    /** @var array<int, TypedForeachBinding> */
    private array $typedForeachBindings = [];

    private int $nestedCallableDepth = 0;

    public function __construct(
        private readonly WhenFragmentParser $fragments = new WhenFragmentParser(),
        private readonly ExpressionTypeResolver $expressionTypes = new ExpressionTypeResolver(),
        private readonly TypeCompatibility $compatibility = new TypeCompatibility(),
        private readonly TypeResolver $types = new TypeResolver(),
        private readonly SourceNameResolver $names = new SourceNameResolver(),
    ) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->parsed = [];
        $this->locations = [];
        $this->typedLocals = [];
        $this->typedForeachBindings = [];
        $this->nestedCallableDepth = 0;

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $local) {
            $this->typedLocals[$local->variableSpan->start->offset] = $local;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->typedForeachBindings[$binding->variableSpan->start->offset] = $binding;
        }

        foreach ($context->parsedFile->extensionSyntax->whenExpressions as $when) {
            $this->parseExpression($when);
        }

        foreach ($context->parsedFile->statements as $statement) {
            $this->indexNode($statement, null, [], false, false);
        }

        foreach ($this->parsed as $expression) {
            foreach ($expression->branches as $branch) {
                if ($branch->condition !== null) {
                    $this->indexNode($branch->condition, null, [], true, true);
                }
                foreach ($branch->statements as $statement) {
                    $this->indexNode($statement, null, [], true, false);
                }
            }
        }

        foreach ($context->parsedFile->extensionSyntax->whenExpressions as $when) {
            if ($when->parentId !== null || $context->model->whenExpressions->find($when->id) !== null) {
                continue;
            }

            $location = $this->locations[$when->id->value] ?? null;
            if ($location === null) {
                $this->addDiagnostic(
                    DiagnosticCode::InternalCompilerError,
                    'A parsed `when` expression could not be associated with its normalized placeholder.',
                    $when->span,
                );
                continue;
            }

            $this->analyzeExpression($when, $this->createOuterScope($when, $location));
        }
    }

    private function parseExpression(WhenExpression $when): void
    {
        $branches = [];

        foreach ($when->branches as $branch) {
            $condition = $this->fragments->parseCondition($this->context->parsedFile, $branch->conditionSpan);
            $body = $this->fragments->parseBody($this->context->parsedFile, $branch->bodySpan);
            $this->context->model->diagnostics->addAll($condition->diagnostics);
            $this->context->model->diagnostics->addAll($body->diagnostics);
            $branches[] = new WhenParsedBranch($branch, $condition->expression, $body->statements);
        }

        $body = $this->fragments->parseBody($this->context->parsedFile, $when->elseBranch->bodySpan);
        $this->context->model->diagnostics->addAll($body->diagnostics);
        $branches[] = new WhenParsedBranch($when->elseBranch, null, $body->statements);
        $this->parsed[$when->id->value] = new WhenParsedExpression($when, $branches);
    }

    /** @param list<Node> $ancestors */
    private function indexNode(
        Node $node,
        ?Node $parent,
        array $ancestors,
        bool $fragment,
        bool $condition,
    ): void {
        $whenId = $node->getAttribute('ppphpWhenExpressionId');

        if (is_string($whenId) && $node instanceof Expr) {
            $site = $condition ? WhenExpressionSite::Unsupported : $this->resolveSite($node, $parent, $ancestors);
            $statementAncestors = $parent === null ? $ancestors : [$parent, ...$ancestors];
            $statement = $this->resolveStatement($node, $statementAncestors);
            $this->locations[$whenId] = new WhenExpressionLocation(
                $site,
                $node,
                $statement,
                $parent,
                $ancestors,
                $fragment,
            );
        }

        $nextAncestors = $parent === null ? $ancestors : [$parent, ...$ancestors];

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->indexNode($value, $node, $nextAncestors, $fragment, $condition);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->indexNode($child, $node, $nextAncestors, $fragment, $condition);
                    }
                }
            }
        }
    }

    /** @param list<Node> $ancestors */
    private function resolveSite(Expr $placeholder, ?Node $parent, array $ancestors): WhenExpressionSite
    {
        if ($parent instanceof Expr\Assign && $parent->expr === $placeholder) {
            foreach ($this->typedLocals as $local) {
                if ($local->initializerSpan->start->offset === $this->span($placeholder)->start->offset) {
                    return WhenExpressionSite::TypedLocalInitializer;
                }
            }

            return WhenExpressionSite::Assignment;
        }

        if ($parent instanceof Stmt\Return_ && $parent->expr === $placeholder) {
            return WhenExpressionSite::ReturnOperand;
        }

        if (
            $parent instanceof Arg
            && $parent->value === $placeholder
            && !$parent->unpack
            && array_any($ancestors, static fn (Node $ancestor): bool => $ancestor instanceof Expr\CallLike)
        ) {
            return WhenExpressionSite::CallArgument;
        }

        if ($parent instanceof ArrayItem && $parent->value === $placeholder && !$parent->unpack) {
            return WhenExpressionSite::ArrayValue;
        }

        return WhenExpressionSite::Unsupported;
    }

    /** @param list<Node> $ancestors */
    private function resolveStatement(Node $node, array $ancestors): Node
    {
        if ($node instanceof Stmt) {
            return $node;
        }

        foreach ($ancestors as $ancestor) {
            if ($ancestor instanceof Stmt && !$ancestor instanceof Stmt\ElseIf_ && !$ancestor instanceof Stmt\Else_) {
                return $ancestor;
            }
        }

        return $node;
    }

    private function analyzeExpression(WhenExpression $when, Scope $outerScope): WhenExpressionAnalysis
    {
        $existing = $this->context->model->whenExpressions->find($when->id);
        if ($existing !== null) {
            return $existing;
        }

        $parsed = $this->parsed[$when->id->value] ?? null;
        $location = $this->locations[$when->id->value] ?? null;

        if ($parsed === null || $location === null) {
            throw new \LogicException('A when expression must be parsed and located before analysis.');
        }

        if ($location->site === WhenExpressionSite::Unsupported) {
            $this->addDiagnostic(
                DiagnosticCode::WhenPositionNotSupported,
                'This `when` expression is not in a supported Stage 9 value position.',
                $when->span,
            );
        }

        $analyses = [];
        $allTypes = [];

        foreach ($parsed->branches as $branch) {
            $scope = $this->copyScope($outerScope, 'when-branch');

            if ($branch->condition !== null) {
                $this->inspectExpression($branch->condition, $scope);
            }

            $flow = $this->analyzeStatements($branch->statements, $scope);
            if ($flow['canComplete']) {
                $this->addDiagnostic(
                    DiagnosticCode::WhenBranchDoesNotProduceValue,
                    'Every reachable path through a `when` branch must yield a value or terminate.',
                    $branch->syntax->bodySpan,
                );
            }

            array_push($allTypes, ...$flow['types']);
            $analyses[] = new WhenBranchAnalysis(
                $branch->syntax,
                $branch->condition,
                $branch->statements,
                $this->mergeTypes($flow['types']),
                $flow['spans'],
                $flow['canComplete'],
            );
        }

        $resultType = $this->mergeTypes($allTypes);
        $analysis = new WhenExpressionAnalysis(
            $when,
            $location->site,
            $location->placeholder,
            $location->statement,
            $analyses,
            $resultType,
            $this->createTemporaryName($when),
        );
        $this->context->model->whenExpressions->record($analysis);
        $this->checkContextType($analysis, $outerScope);
        $this->checkByReferenceArgument($analysis, $outerScope);

        return $analysis;
    }

    /**
     * @param list<Stmt> $statements
     * @return array{canComplete: bool, types: list<LocalType>, spans: list<Span>}
     */
    private function analyzeStatements(array $statements, Scope $scope): array
    {
        $flow = ['canComplete' => true, 'types' => [], 'spans' => []];

        foreach ($statements as $statement) {
            if (!$flow['canComplete']) {
                break;
            }

            $next = $this->analyzeStatement($statement, $scope);
            array_push($flow['types'], ...$next['types']);
            array_push($flow['spans'], ...$next['spans']);
            $flow['canComplete'] = $next['canComplete'];
        }

        return $flow;
    }

    /** @return array{canComplete: bool, types: list<LocalType>, spans: list<Span>} */
    private function analyzeStatement(Stmt $statement, Scope $scope): array
    {
        if ($statement instanceof Stmt\Return_) {
            if ($statement->expr === null) {
                $this->addDiagnostic(
                    DiagnosticCode::WhenResultRequiresValue,
                    'A branch-level `return` must provide the `when` result value.',
                    $this->span($statement),
                );

                return ['canComplete' => false, 'types' => [], 'spans' => []];
            }

            $this->inspectExpression($statement->expr, $scope);

            return [
                'canComplete' => false,
                'types' => [$this->resolveExpressionType($statement->expr, $scope)],
                'spans' => [$this->span($statement->expr)],
            ];
        }

        if ($statement instanceof Stmt\If_) {
            $this->inspectExpression($statement->cond, $scope);
            $flows = [$this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-if'))];
            foreach ($statement->elseifs as $elseif) {
                $this->inspectExpression($elseif->cond, $scope);
                $flows[] = $this->analyzeStatements(array_values($elseif->stmts), $this->copyScope($scope, 'when-elseif'));
            }
            $flows[] = $statement->else === null
                ? ['canComplete' => true, 'types' => [], 'spans' => []]
                : $this->analyzeStatements(array_values($statement->else->stmts), $this->copyScope($scope, 'when-else'));

            return $this->mergeFlows($flows);
        }

        if ($statement instanceof Stmt\TryCatch) {
            $flows = [$this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-try'))];
            foreach ($statement->catches as $catch) {
                $catchScope = $this->copyScope($scope, 'when-catch');
                if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
                    $catchScope->declare(new VariableSymbol(
                        '$' . $catch->var->name,
                        LocalType::createUnknown(),
                        BindingMutability::Mutable,
                        $this->span($catch->var),
                    ));
                }
                $flows[] = $this->analyzeStatements(array_values($catch->stmts), $catchScope);
            }
            $combined = $this->mergeFlows($flows);
            if ($statement->finally === null) {
                return $combined;
            }
            $finally = $this->analyzeStatements(array_values($statement->finally->stmts), $this->copyScope($scope, 'when-finally'));
            if (!$finally['canComplete']) {
                return $finally;
            }
            array_push($combined['types'], ...$finally['types']);
            array_push($combined['spans'], ...$finally['spans']);

            return $combined;
        }

        if ($statement instanceof Stmt\Foreach_) {
            $this->inspectExpression($statement->expr, $scope);
            $this->declareForeachTarget($statement->keyVar, $scope);
            $this->declareForeachTarget($statement->valueVar, $scope);
            $body = $this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-loop'));
            $body['canComplete'] = true;

            return $body;
        }

        if (
            $statement instanceof Stmt\For_
            || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Do_
        ) {
            $this->inspectNode($statement, $scope, true);
            $body = $this->analyzeStatements(array_values($statement->stmts), $this->copyScope($scope, 'when-loop'));
            $body['canComplete'] = true;

            return $body;
        }

        if ($statement instanceof Stmt\Switch_) {
            $this->inspectExpression($statement->cond, $scope);
            $flows = [];
            $hasDefault = false;
            foreach ($statement->cases as $case) {
                $hasDefault = $hasDefault || $case->cond === null;
                if ($case->cond !== null) {
                    $this->inspectExpression($case->cond, $scope);
                }
                $flows[] = $this->analyzeStatements(array_values($case->stmts), $this->copyScope($scope, 'when-case'));
            }
            if (!$hasDefault) {
                $flows[] = ['canComplete' => true, 'types' => [], 'spans' => []];
            }

            return $this->mergeFlows($flows);
        }

        if (($statement instanceof Stmt\Break_ || $statement instanceof Stmt\Continue_) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenControlTransferNotAllowed,
                '`break` and `continue` cannot originate in a `when` branch.',
                $this->span($statement),
            );

            return ['canComplete' => false, 'types' => [], 'spans' => []];
        }

        if (($statement instanceof Stmt\Goto_ || $statement instanceof Stmt\Label) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenGotoNotAllowed,
                '`goto` and labels cannot appear in a `when` branch.',
                $this->span($statement),
            );
        }

        $this->inspectNode($statement, $scope, false);
        $terminates = $statement instanceof Stmt\Expression
            && ($statement->expr instanceof Expr\Throw_ || $statement->expr instanceof Expr\Exit_ || $this->resolveTerminatingExpression($statement->expr, $scope));

        return ['canComplete' => !$terminates, 'types' => [], 'spans' => []];
    }

    private function inspectNode(Node $node, Scope $scope, bool $skipLoopBody): void
    {
        if ($node instanceof Expr) {
            $this->inspectExpression($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            $callableScope = new Scope('when-nested-callable');
            foreach ($node->params as $parameter) {
                $this->declareParameter($parameter, $callableScope);
            }
            $this->nestedCallableDepth++;
            foreach ($node->stmts ?? [] as $statement) {
                $this->inspectNode($statement, $callableScope, false);
            }
            $this->nestedCallableDepth--;

            return;
        }

        if (($node instanceof Stmt\Break_ || $node instanceof Stmt\Continue_) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenControlTransferNotAllowed,
                '`break` and `continue` cannot originate in a `when` branch.',
                $this->span($node),
            );

            return;
        }

        if (($node instanceof Stmt\Goto_ || $node instanceof Stmt\Label) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenGotoNotAllowed,
                '`goto` and labels cannot appear in a `when` branch.',
                $this->span($node),
            );

            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            if ($skipLoopBody && $name === 'stmts') {
                continue;
            }
            $value = $node->{$name};
            if ($value instanceof Node) {
                $this->inspectNode($value, $scope, false);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->inspectNode($child, $scope, false);
                    }
                }
            }
        }
    }

    private function inspectExpression(Expr $expression, Scope $scope): void
    {
        $whenId = $expression->getAttribute('ppphpWhenExpressionId');
        if (is_string($whenId)) {
            $syntax = $this->parsed[$whenId]->syntax ?? null;
            if ($syntax !== null) {
                $this->analyzeExpression($syntax, $scope);
            }

            return;
        }

        if (($expression instanceof Expr\Yield_ || $expression instanceof Expr\YieldFrom) && $this->nestedCallableDepth === 0) {
            $this->addDiagnostic(
                DiagnosticCode::WhenYieldNotAllowed,
                '`yield` and `yield from` cannot appear in a `when` branch.',
                $this->span($expression),
            );
        }

        if ($expression instanceof Expr\Assign) {
            $this->inspectExpression($expression->expr, $scope);
            $this->inspectAssignment($expression, $scope);

            return;
        }

        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            $callableScope = new Scope('when-nested-callable');
            foreach ($expression->params as $parameter) {
                $this->declareParameter($parameter, $callableScope);
            }
            $this->nestedCallableDepth++;
            if ($expression instanceof Expr\Closure) {
                foreach ($expression->uses as $use) {
                    if (is_string($use->var->name) && ($symbol = $scope->resolve('$' . $use->var->name)) !== null) {
                        $callableScope->import($symbol);
                    }
                }
                foreach ($expression->stmts as $statement) {
                    $this->inspectNode($statement, $callableScope, false);
                }
            } else {
                $this->inspectExpression($expression->expr, $callableScope);
            }
            $this->nestedCallableDepth--;

            return;
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            $name = '$' . $expression->name;
            $symbol = $scope->resolve($name);
            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::LocalVariableNotDeclared,
                    sprintf('%s must be declared before it can be read.', $name),
                    $this->span($expression),
                );
            } else {
                $symbol->binding?->recordRead($this->span($expression));
            }

            return;
        }

        foreach ($expression->getSubNodeNames() as $name) {
            $value = $expression->{$name};
            if ($value instanceof Expr) {
                $this->inspectExpression($value, $scope);
            } elseif ($value instanceof Node) {
                $this->inspectNode($value, $scope, false);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Expr) {
                        $this->inspectExpression($child, $scope);
                    } elseif ($child instanceof Node) {
                        $this->inspectNode($child, $scope, false);
                    }
                }
            }
        }
    }

    private function inspectAssignment(Expr\Assign $assignment, Scope $scope): void
    {
        if (!$assignment->var instanceof Expr\Variable || !is_string($assignment->var->name)) {
            $this->inspectNode($assignment->var, $scope, false);

            return;
        }

        $name = '$' . $assignment->var->name;
        $offset = $this->span($assignment->var)->start->offset;
        $declaration = $this->typedLocals[$offset] ?? null;
        $actual = $this->resolveExpressionType($assignment->expr, $scope);

        if ($declaration !== null) {
            $existing = $scope->resolve($name);
            if ($existing !== null) {
                $this->addDiagnostic(
                    DiagnosticCode::DuplicateLocalDeclaration,
                    sprintf('%s cannot shadow a binding visible to this `when` branch.', $name),
                    $declaration->variableSpan,
                    [new DiagnosticLabel($existing->declarationSpan ?? $declaration->variableSpan, 'The visible binding is declared here.')],
                );

                return;
            }

            $declared = LocalType::createFromSourceType($declaration->type);
            if (!$this->compatibility->accepts($declared, $actual, $this->context->symbols)) {
                $this->addDiagnostic(
                    DiagnosticCode::InitializerNotAssignableToDeclaredType,
                    sprintf('Initializer of type %s is not assignable to declared type %s.', $actual->text, $declared->text),
                    $declaration->initializerSpan,
                    [new DiagnosticLabel($declaration->type->span, 'The local type is declared here.')],
                );
            }

            $binding = new LocalBinding(
                $declaration->id,
                $name,
                $declared,
                $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
                $declaration->span,
                $declaration->variableSpan,
                $declaration->initializerSpan,
                $assignment->expr,
                $actual,
            );
            $binding->recordWrite($declaration->variableSpan);
            $this->context->model->bindings->record($binding);
            $scope->declare(new VariableSymbol($name, $declared, $binding->mutability, $declaration->variableSpan, $binding));

            return;
        }

        $symbol = $scope->resolve($name);
        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentCannotDeclareVariable,
                sprintf('%s must be declared with an explicit type before it can be assigned.', $name),
                $this->span($assignment->var),
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                sprintf('%s cannot be assigned because it is readonly.', $name),
                $this->span($assignment->var),
                [new DiagnosticLabel($symbol->declarationSpan ?? $this->span($assignment->var), 'The readonly binding is declared here.')],
            );

            return;
        }

        if (!$this->compatibility->accepts($symbol->type, $actual, $this->context->symbols)) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                sprintf('Value of type %s is not assignable to %s of type %s.', $actual->text, $name, $symbol->type->text),
                $this->span($assignment->expr),
            );
        }
        $symbol->binding?->recordWrite($this->span($assignment->var));
    }

    private function resolveExpressionType(Expr $expression, Scope $scope): LocalType
    {
        $when = $this->context->model->whenExpressions->findPlaceholder($expression);
        if ($when !== null) {
            return $when->resultType;
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $name = $this->names->resolve($this->context->parsedFile, $expression->name->toString(), $this->span($expression)->start->offset);
            $symbol = $this->context->symbols->findFunction($name) ?? $this->context->symbols->findFunction($expression->name->toString());

            return $symbol?->returnType === null ? LocalType::createUnknown() : LocalType::createFromText($symbol->returnType->text);
        }

        if ($expression instanceof Expr\Array_) {
            return $this->resolveArrayLiteralType($expression, $scope);
        }

        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $classOffset = $this->span($expression->class)->start->offset;
            foreach ($this->context->parsedFile->extensionSyntax->genericTypes as $reference) {
                if ($reference->nameSpan->start->offset === $classOffset) {
                    return LocalType::createFromText($reference->span->text);
                }
            }
            $name = $this->names->resolve($this->context->parsedFile, $expression->class->toString(), $this->span($expression)->start->offset);

            return LocalType::createAtomic($name);
        }

        if (($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) && $expression->name instanceof Node\Identifier) {
            $owner = $this->resolveExpressionType($expression->var, $scope)->resolveSingleNamedType();
            $method = $owner === null ? null : $this->resolveMethod($owner, $expression->name->toString());

            return $method?->returnType === null ? LocalType::createUnknown() : LocalType::createFromText($method->returnType->text);
        }

        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
            $owner = $this->names->resolve($this->context->parsedFile, $expression->class->toString(), $this->span($expression)->start->offset);
            $method = $this->resolveMethod($owner, $expression->name->toString());

            return $method?->returnType === null ? LocalType::createUnknown() : LocalType::createFromText($method->returnType->text);
        }

        return $this->expressionTypes->resolve($expression, $scope);
    }

    private function checkContextType(WhenExpressionAnalysis $analysis, Scope $scope): void
    {
        $expected = $this->resolveExpectedType($analysis, $scope);
        if ($expected === null || $analysis->resultType->unknown || $this->compatibility->accepts($expected, $analysis->resultType, $this->context->symbols)) {
            return;
        }

        $related = [];
        foreach ($analysis->branches as $branch) {
            foreach ($branch->resultSpans as $span) {
                $related[] = new DiagnosticLabel($span, sprintf('This branch contributes %s.', $branch->resultType->text));
            }
        }

        $this->addDiagnostic(
            DiagnosticCode::WhenResultTypeDoesNotMatch,
            sprintf('The `when` result type %s is not assignable to expected type %s.', $analysis->resultType->text, $expected->text),
            $analysis->syntax->span,
            $related,
        );
    }

    private function resolveExpectedType(WhenExpressionAnalysis $analysis, Scope $scope): ?LocalType
    {
        $location = $this->locations[$analysis->syntax->id->value];

        if ($analysis->site === WhenExpressionSite::TypedLocalInitializer) {
            foreach ($this->typedLocals as $local) {
                if ($local->initializerSpan->start->offset === $analysis->syntax->span->start->offset) {
                    return LocalType::createFromSourceType($local->type);
                }
            }
        }

        if ($analysis->site === WhenExpressionSite::Assignment && $location->parent instanceof Expr\Assign && $location->parent->var instanceof Expr\Variable && is_string($location->parent->var->name)) {
            return $scope->resolve('$' . $location->parent->var->name)?->type;
        }

        if ($analysis->site === WhenExpressionSite::Assignment && $location->parent instanceof Expr\Assign) {
            return $this->resolveAssignmentTargetType($location->parent->var, $scope);
        }

        if ($analysis->site === WhenExpressionSite::ReturnOperand) {
            foreach ($location->ancestors as $ancestor) {
                if ($ancestor instanceof Stmt\Function_ && $ancestor->name->toString() === '__ppphp_when_fragment') {
                    return null;
                }
                if ($ancestor instanceof Stmt\Function_ || $ancestor instanceof Stmt\ClassMethod || $ancestor instanceof Expr\Closure || $ancestor instanceof Expr\ArrowFunction) {
                    $type = $this->types->resolve($ancestor->returnType);

                    return $type === null ? null : LocalType::createFromText($type->text);
                }
            }
        }

        if ($analysis->site === WhenExpressionSite::CallArgument) {
            return $this->resolveArgumentParameter($location, $scope)?->type === null
                ? null
                : LocalType::createFromText($this->resolveArgumentParameter($location, $scope)->type->text);
        }

        if ($analysis->site === WhenExpressionSite::ArrayValue) {
            foreach ($location->ancestors as $ancestor) {
                if (!$ancestor instanceof Expr\Assign) {
                    continue;
                }
                $declaration = $ancestor->var instanceof Expr\Variable
                    ? $this->typedLocals[$this->span($ancestor->var)->start->offset] ?? null
                    : null;
                $type = $declaration === null ? null : LocalType::createFromSourceType($declaration->type)->semanticType;
                if ($type instanceof TypedArrayType) {
                    return LocalType::createFromSemanticType($type->valueType);
                }
            }
        }

        return null;
    }

    private function checkByReferenceArgument(WhenExpressionAnalysis $analysis, Scope $scope): void
    {
        if ($analysis->site !== WhenExpressionSite::CallArgument) {
            return;
        }

        $location = $this->locations[$analysis->syntax->id->value];
        $parameter = $this->resolveArgumentParameter($location, $scope);
        if (($location->parent instanceof Arg && $location->parent->byRef) || $parameter?->byReference === true) {
            $this->addDiagnostic(
                DiagnosticCode::WhenByReferenceArgumentNotAllowed,
                'A `when` result cannot be passed to a known by-reference parameter.',
                $analysis->syntax->span,
                $parameter === null ? [] : [new DiagnosticLabel($parameter->declarationSpan, 'The by-reference parameter is declared here.')],
            );
        }
    }

    private function resolveArgumentParameter(WhenExpressionLocation $location, Scope $scope): ?ParameterSymbol
    {
        if (!$location->parent instanceof Arg) {
            return null;
        }

        $call = null;
        foreach ($location->ancestors as $ancestor) {
            if ($ancestor instanceof Expr\CallLike) {
                $call = $ancestor;
                break;
            }
        }
        if ($call === null) {
            return null;
        }

        $position = array_search($location->parent, $call->getArgs(), true);
        if (!is_int($position)) {
            return null;
        }

        $parameters = [];
        if ($call instanceof Expr\FuncCall && $call->name instanceof Node\Name) {
            $name = $this->names->resolve($this->context->parsedFile, $call->name->toString(), $this->span($call)->start->offset);
            $function = $this->context->symbols->findFunction($name) ?? $this->context->symbols->findFunction($call->name->toString());
            $parameters = $function === null ? [] : $function->parameters;
        } elseif ($call instanceof Expr\New_ && $call->class instanceof Node\Name) {
            $name = $this->names->resolve($this->context->parsedFile, $call->class->toString(), $this->span($call)->start->offset);
            $constructor = $this->context->symbols->findClass($name)?->findMethod('__construct');
            $parameters = $constructor === null ? [] : $constructor->parameters;
        } elseif ($call instanceof Expr\StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            $name = $this->names->resolve($this->context->parsedFile, $call->class->toString(), $this->span($call)->start->offset);
            $method = $this->resolveMethod($name, $call->name->toString());
            $parameters = $method === null ? [] : $method->parameters;
        } elseif (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            $owner = $this->resolveExpressionType($call->var, $scope)->resolveSingleNamedType();
            $method = $owner === null ? null : $this->resolveMethod($owner, $call->name->toString());
            $parameters = $method === null ? [] : $method->parameters;
        }

        if ($location->parent->name !== null) {
            foreach ($parameters as $parameter) {
                if (strcasecmp(ltrim($parameter->name, '$'), $location->parent->name->toString()) === 0) {
                    return $parameter;
                }
            }

            return null;
        }

        return $parameters[$position] ?? null;
    }

    private function createOuterScope(WhenExpression $when, WhenExpressionLocation $location): Scope
    {
        $scope = new Scope('when-outer');
        $callable = null;
        foreach ($location->ancestors as $ancestor) {
            if ($ancestor instanceof Stmt\Function_ || $ancestor instanceof Stmt\ClassMethod || $ancestor instanceof Expr\Closure || $ancestor instanceof Expr\ArrowFunction) {
                if (!$ancestor instanceof Stmt\Function_ || $ancestor->name->toString() !== '__ppphp_when_fragment') {
                    $callable = $ancestor;
                    break;
                }
            }
        }

        if ($callable !== null) {
            foreach ($callable->params as $parameter) {
                $this->declareParameter($parameter, $scope);
            }
            if ($callable instanceof Stmt\ClassMethod) {
                $owner = $this->resolveOwningClass($callable);
                $scope->declare(new VariableSymbol(
                    '$this',
                    $owner === null ? LocalType::createAtomic('object') : LocalType::createAtomic($owner),
                    BindingMutability::Mutable,
                    $this->span($callable),
                ));
            }
        }

        $callableSpan = $callable === null ? null : $this->span($callable);
        foreach ($this->context->model->bindings->bindings as $binding) {
            if ($binding->declarationSpan->start->offset >= $when->span->start->offset) {
                continue;
            }
            if ($callableSpan !== null && (
                $binding->declarationSpan->start->offset < $callableSpan->start->offset
                || $binding->declarationSpan->end->offset > $callableSpan->end->offset
            )) {
                continue;
            }
            $scope->declare(new VariableSymbol($binding->name, $binding->type, $binding->mutability, $binding->variableSpan, $binding));
        }

        return $scope;
    }

    private function declareParameter(Param $parameter, Scope $scope): void
    {
        if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
            return;
        }
        $type = $this->types->resolve($parameter->type);
        $scope->declare(new VariableSymbol(
            '$' . $parameter->var->name,
            $type === null ? LocalType::createUnknown() : LocalType::createFromText($type->text),
            BindingMutability::Mutable,
            $this->span($parameter->var),
        ));
    }

    private function copyScope(Scope $scope, string $kind): Scope
    {
        $copy = new Scope($kind);
        foreach ($scope->symbols as $symbol) {
            $copy->import($symbol);
        }

        return $copy;
    }

    private function declareForeachTarget(?Expr $target, Scope $scope): void
    {
        if (!$target instanceof Expr\Variable || !is_string($target->name)) {
            if ($target !== null) {
                $this->inspectExpression($target, $scope);
            }

            return;
        }

        $name = '$' . $target->name;
        $span = $this->span($target);
        $declaration = $this->typedForeachBindings[$span->start->offset] ?? null;

        if ($declaration === null) {
            $symbol = $scope->resolve($name);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentCannotDeclareVariable,
                    sprintf('%s must be declared with an explicit type before it can be assigned.', $name),
                    $span,
                );
            } elseif ($symbol->mutability === BindingMutability::Readonly) {
                $this->addDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                    sprintf('%s cannot be assigned because it is readonly.', $name),
                    $span,
                );
            } else {
                $symbol->binding?->recordWrite($span);
            }

            return;
        }

        $existing = $scope->resolve($name);
        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                sprintf('%s cannot shadow a binding visible to this `when` branch.', $name),
                $declaration->variableSpan,
                [new DiagnosticLabel($existing->declarationSpan ?? $declaration->variableSpan, 'The visible binding is declared here.')],
            );

            return;
        }

        $type = LocalType::createFromSourceType($declaration->type);
        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $type,
            BindingMutability::Mutable,
            $declaration->span,
            $declaration->variableSpan,
            null,
            null,
            LocalType::createUnknown(),
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $type,
            BindingMutability::Mutable,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function resolveArrayLiteralType(Expr\Array_ $array, Scope $scope): LocalType
    {
        if ($array->items === []) {
            return LocalType::createAtomic('array');
        }

        $values = [];
        $keys = [];
        $list = true;

        foreach ($array->items as $item) {
            if ($item->unpack) {
                return LocalType::createAtomic('array');
            }

            $values[] = $this->resolveExpressionType($item->value, $scope);
            if ($item->key === null) {
                $keys[] = LocalType::createAtomic('int');
                continue;
            }

            $list = false;
            $keys[] = $this->resolveExpressionType($item->key, $scope);
        }

        $value = $this->mergeTypes($values);
        $key = $this->mergeTypes($keys);

        if ($value->unknown || $key->unknown) {
            return LocalType::createAtomic('array');
        }

        return LocalType::createFromText($list
            ? sprintf('array<%s>', $value->canonical)
            : sprintf('array<%s,%s>', $key->canonical, $value->canonical));
    }

    private function resolveAssignmentTargetType(Expr $target, Scope $scope): ?LocalType
    {
        if ($target instanceof Expr\ArrayDimFetch) {
            $type = $this->resolveExpressionType($target->var, $scope)->semanticType;
            $value = $this->resolveArrayValueType($type);

            return $value === null ? null : LocalType::createFromSemanticType($value);
        }

        if ($target instanceof Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
            $owner = $this->resolveExpressionType($target->var, $scope)->resolveSingleNamedType();
            $property = $owner === null ? null : $this->resolveProperty($owner, $target->name->toString());

            return $property?->type === null ? null : LocalType::createFromText($property->type->text);
        }

        if ($target instanceof Expr\StaticPropertyFetch && $target->class instanceof Node\Name && $target->name instanceof Node\VarLikeIdentifier) {
            $owner = $this->names->resolve(
                $this->context->parsedFile,
                $target->class->toString(),
                $this->span($target)->start->offset,
            );
            $property = $this->resolveProperty($owner, $target->name->toString());

            return $property?->type === null ? null : LocalType::createFromText($property->type->text);
        }

        return null;
    }

    private function resolveArrayValueType(Type $type): ?Type
    {
        if ($type instanceof TypedArrayType) {
            return $type->valueType;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $values = [];
        foreach ($type->members as $member) {
            $value = $this->resolveArrayValueType($member);
            if ($value !== null) {
                $values[$value->canonical] = $value;
            }
        }

        if ($values === []) {
            return null;
        }

        return count($values) === 1 ? reset($values) : new UnionType(array_values($values));
    }

    private function resolveOwningClass(Stmt\ClassMethod $method): ?string
    {
        $span = $this->span($method);

        foreach ($this->context->symbols->classes as $class) {
            if (
                $class->sourceFile === $this->context->parsedFile->sourceFile
                && $class->declarationSpan->start->offset <= $span->start->offset
                && $class->declarationSpan->end->offset >= $span->end->offset
            ) {
                return $class->fullyQualifiedName;
            }
        }

        return null;
    }

    private function resolveMethod(string $className, string $methodName): ?\Amasiye\Ppphp\Semantic\Symbol\MethodSymbol
    {
        $visited = [];

        return $this->resolveMethodInHierarchy($className, $methodName, $visited);
    }

    /** @param array<string, true> $visited */
    private function resolveMethodInHierarchy(string $className, string $methodName, array &$visited): ?\Amasiye\Ppphp\Semantic\Symbol\MethodSymbol
    {
        $key = strtolower(ltrim($className, '\\'));
        if (isset($visited[$key])) {
            return null;
        }
        $visited[$key] = true;
        $class = $this->context->symbols->findClass($className);
        $method = $class?->findMethod($methodName);
        if ($method !== null || $class === null) {
            return $method;
        }

        foreach ([...$class->traits, ...$class->interfaces, ...($class->parent === null ? [] : [$class->parent])] as $ancestor) {
            $method = $this->resolveMethodInHierarchy($ancestor, $methodName, $visited);
            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }

    private function resolveProperty(string $className, string $propertyName): ?\Amasiye\Ppphp\Semantic\Symbol\PropertySymbol
    {
        $visited = [];

        return $this->resolvePropertyInHierarchy($className, $propertyName, $visited);
    }

    /** @param array<string, true> $visited */
    private function resolvePropertyInHierarchy(string $className, string $propertyName, array &$visited): ?\Amasiye\Ppphp\Semantic\Symbol\PropertySymbol
    {
        $key = strtolower(ltrim($className, '\\'));
        if (isset($visited[$key])) {
            return null;
        }
        $visited[$key] = true;
        $class = $this->context->symbols->findClass($className);
        $property = $class?->findProperty($propertyName);
        if ($property !== null || $class === null) {
            return $property;
        }

        foreach ([...$class->traits, ...($class->parent === null ? [] : [$class->parent])] as $ancestor) {
            $property = $this->resolvePropertyInHierarchy($ancestor, $propertyName, $visited);
            if ($property !== null) {
                return $property;
            }
        }

        return null;
    }

    /**
     * @param list<array{canComplete: bool, types: list<LocalType>, spans: list<Span>}> $flows
     * @return array{canComplete: bool, types: list<LocalType>, spans: list<Span>}
     */
    private function mergeFlows(array $flows): array
    {
        $combined = ['canComplete' => false, 'types' => [], 'spans' => []];
        foreach ($flows as $flow) {
            $combined['canComplete'] = $combined['canComplete'] || $flow['canComplete'];
            array_push($combined['types'], ...$flow['types']);
            array_push($combined['spans'], ...$flow['spans']);
        }

        return $combined;
    }

    /** @param list<LocalType> $types */
    private function mergeTypes(array $types): LocalType
    {
        if ($types === []) {
            return LocalType::createAtomic('never');
        }
        foreach ($types as $type) {
            if ($type->unknown) {
                return LocalType::createUnknown();
            }
        }
        $members = [];
        foreach ($types as $type) {
            if (!$type->includes('never')) {
                $members[$type->canonical] = $type->canonical;
            }
        }

        return $members === []
            ? LocalType::createAtomic('never')
            : LocalType::createFromText(implode('|', array_values($members)));
    }

    private function resolveTerminatingExpression(Expr $expression, Scope $scope): bool
    {
        if (!$expression instanceof Expr\CallLike) {
            return false;
        }

        return $this->resolveExpressionType($expression, $scope)->includes('never');
    }

    private function createTemporaryName(WhenExpression $when): string
    {
        $base = '__ppphp_when_' . $when->span->start->offset;
        $name = $base;
        $suffix = 0;
        while (preg_match('/\\$' . preg_quote($name, '/') . '\\b/', $this->context->parsedFile->sourceFile->contents) === 1) {
            $name = $base . '_' . ++$suffix;
        }

        return '$' . $name;
    }

    private function span(Node $node): Span
    {
        $start = $node->getAttribute('ppphpOriginalStart');
        $end = $node->getAttribute('ppphpOriginalEnd');
        if (is_int($start) && is_int($end)) {
            return $this->context->parsedFile->sourceFile->createSpan($start, $end);
        }

        $start = max(0, $node->getStartFilePos());
        $end = max($start, $node->getEndFilePos() + 1);

        return $this->context->parsedFile->sourceFile->createSpan(
            min($start, $this->context->parsedFile->sourceFile->length),
            min($end, $this->context->parsedFile->sourceFile->length),
        );
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $message,
        Span $span,
        array $related = [],
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
        ));
    }
}
