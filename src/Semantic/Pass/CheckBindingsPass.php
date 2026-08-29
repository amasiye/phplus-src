<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Frontend\Ast\TypedForInitializer;
use Amasiye\Ppphp\Frontend\Ast\TypedForeachBinding;
use Amasiye\Ppphp\Frontend\Ast\TypedLocalDeclaration;
use Amasiye\Ppphp\Frontend\Ast\Enumerations\ForeachBindingPosition;
use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingInitialization;
use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Amasiye\Ppphp\Semantic\Binding\LocalBinding;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\Scope\Scope;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\Symbol\VariableSymbol;
use Amasiye\Ppphp\Semantic\Type\ExpressionTypeResolver;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Semantic\Type\TypeCompatibility;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

final class CheckBindingsPass implements SemanticPass
{
    private const MUTATING_FUNCTIONS = [
        'array_multisort',
        'array_pop',
        'array_push',
        'array_shift',
        'array_splice',
        'array_unshift',
        'arsort',
        'asort',
        'end',
        'krsort',
        'ksort',
        'next',
        'prev',
        'reset',
        'rsort',
        'shuffle',
        'sort',
    ];

    private SemanticContext $context;

    /** @var array<int, TypedLocalDeclaration> */
    private array $declarationsByVariableOffset = [];

    /** @var array<int, TypedForInitializer> */
    private array $forInitializersByVariableOffset = [];

    /** @var array<int, TypedForeachBinding> */
    private array $foreachBindingsByVariableOffset = [];

    private readonly ExpressionTypeResolver $expressionTypes;

    private readonly TypeCompatibility $compatibility;

    public function __construct(
        ?ExpressionTypeResolver $expressionTypes = null,
        ?TypeCompatibility $compatibility = null,
    ) {
        $this->expressionTypes = $expressionTypes ?? new ExpressionTypeResolver();
        $this->compatibility = $compatibility ?? new TypeCompatibility();
    }

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;
        $this->declarationsByVariableOffset = [];
        $this->forInitializersByVariableOffset = [];
        $this->foreachBindingsByVariableOffset = [];

        foreach ($context->parsedFile->extensionSyntax->typedLocals as $declaration) {
            $this->declarationsByVariableOffset[$declaration->variableSpan->start->offset] = $declaration;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForInitializers as $declaration) {
            $this->forInitializersByVariableOffset[$declaration->variableSpan->start->offset] = $declaration;
        }

        foreach ($context->parsedFile->extensionSyntax->typedForeachBindings as $binding) {
            $this->foreachBindingsByVariableOffset[$binding->variableSpan->start->offset] = $binding;
        }

        $scope = $this->createScope('file');
        $this->enterScope($scope);

        foreach ($context->parsedFile->statements as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processNode(Node $node, Scope $scope): void
    {
        if ($node instanceof Stmt\Function_) {
            $this->processNamedFunction($node);

            return;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->processClassMethod($node);

            return;
        }

        if ($node instanceof Node\PropertyHook) {
            $this->processPropertyHook($node);

            return;
        }

        if ($node instanceof Expr\Closure) {
            $this->processClosure($node, $scope);

            return;
        }

        if ($node instanceof Expr\ArrowFunction) {
            $this->processArrowFunction($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Foreach_) {
            $this->processForeach($node, $scope);

            return;
        }

        if ($node instanceof Stmt\For_) {
            $this->processFor($node, $scope);

            return;
        }

        if ($node instanceof Stmt\If_) {
            $this->processIf($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Catch_) {
            $this->processCatch($node, $scope);

            return;
        }

        if ($node instanceof Stmt\Global_ || $node instanceof Stmt\Static_) {
            $this->addDiagnostic(
                DiagnosticCode::UnsupportedLocalBindingPosition,
                'Unsupported Local Binding Position',
                'The Stage 5 binding model does not support global or static local declarations.',
                $this->createNodeSpan($node),
            );

            return;
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $variable) {
                $this->processUnsetTarget($variable, $scope);
            }

            return;
        }

        if ($node instanceof Expr\AssignRef) {
            $this->processReferenceAssignment($node, $scope);

            return;
        }

        if ($node instanceof Expr\Assign) {
            $this->processAssignment($node, $scope);

            return;
        }

        if ($node instanceof Expr\AssignOp) {
            $this->processCompoundAssignment($node, $scope);

            return;
        }

        if (
            $node instanceof Expr\PreInc
            || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostInc
            || $node instanceof Expr\PostDec
        ) {
            $this->processIncrementOrDecrement($node->var, $scope);

            return;
        }

        if ($node instanceof Expr\FuncCall) {
            $this->processFunctionCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\StaticCall) {
            $this->processStaticCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->processMethodCall($node, $scope);

            return;
        }

        if ($node instanceof Expr\Variable) {
            $this->processVariableRead($node, $scope);

            return;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $this->processNode($value, $scope);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->processNode($child, $scope);
                    }
                }
            }
        }
    }

    private function processNamedFunction(Stmt\Function_ $function): void
    {
        $scope = $this->createScope('function');
        $this->declareParameters($scope, $function->params);
        $this->enterScope($scope);

        foreach ($function->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processClassMethod(Stmt\ClassMethod $method): void
    {
        $scope = $this->createScope('method');
        $scope->declare(new VariableSymbol(
            '$this',
            LocalType::createAtomic('object'),
            BindingMutability::Mutable,
            $this->createNodeSpan($method),
        ));
        $this->declareParameters($scope, $method->params);
        $this->enterScope($scope);

        foreach ($method->stmts ?? [] as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processPropertyHook(Node\PropertyHook $hook): void
    {
        $scope = $this->createScope('property-hook');
        $scope->declare(new VariableSymbol(
            '$this',
            LocalType::createAtomic('object'),
            BindingMutability::Mutable,
            $this->createNodeSpan($hook),
        ));
        $this->declareParameters($scope, $hook->params);

        if (strtolower($hook->name->toString()) === 'set') {
            $scope->declare(new VariableSymbol(
                '$value',
                LocalType::createUnknown(),
                BindingMutability::Mutable,
                $this->createNodeSpan($hook),
            ));
        }

        $this->enterScope($scope);

        if ($hook->body instanceof Expr) {
            $this->processNode($hook->body, $scope);
        } else {
            foreach ($hook->body ?? [] as $statement) {
                $this->processNode($statement, $scope);
            }
        }

        $this->leaveScope();
    }

    private function processClosure(Expr\Closure $closure, Scope $outerScope): void
    {
        $scope = $this->createScope('closure');
        $this->declareParameters($scope, $closure->params);

        foreach ($closure->uses as $use) {
            $name = $this->resolveVariableName($use->var);
            $symbol = $name === null ? null : $outerScope->resolve($name);
            $span = $this->createNodeSpan($use->var);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::LocalVariableNotDeclared,
                    'Local Variable Is Not Declared',
                    sprintf('Closure capture %s does not refer to a declared local variable.', $name ?? 'variable'),
                    $span,
                );

                continue;
            }

            $symbol->binding?->recordRead($span);

            if ($use->byRef && $symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                    'Readonly Local Cannot Be Referenced',
                    sprintf('%s cannot be captured by reference because it is readonly.', $symbol->name),
                    $span,
                    $symbol,
                );
            }

            $scope->import($symbol);
        }

        $this->enterScope($scope);

        foreach ($closure->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        $this->leaveScope();
    }

    private function processArrowFunction(Expr\ArrowFunction $function, Scope $outerScope): void
    {
        $scope = $this->createScope('arrow-function');
        $this->declareParameters($scope, $function->params);

        foreach ($outerScope->symbols as $symbol) {
            $scope->import($symbol);
        }

        $this->enterScope($scope);
        $this->processNode($function->expr, $scope);
        $this->leaveScope();
    }

    private function processCatch(Stmt\Catch_ $catch, Scope $scope): void
    {
        if ($catch->var instanceof Expr\Variable && is_string($catch->var->name)) {
            $span = $this->createNodeSpan($catch->var);
            $scope->declare(new VariableSymbol(
                '$' . $catch->var->name,
                LocalType::createUnknown(),
                BindingMutability::Mutable,
                $span,
            ));
        }

        foreach ($catch->stmts as $statement) {
            $this->processNode($statement, $scope);
        }
    }

    private function processForeach(Stmt\Foreach_ $foreach, Scope $scope): void
    {
        $this->processNode($foreach->expr, $scope);
        $declaredBindings = [];
        $keyType = LocalType::createAtomic('mixed');
        $valueType = LocalType::createAtomic('mixed');

        if ($foreach->byRef) {
            $this->addDiagnostic(
                DiagnosticCode::UnsupportedLocalBindingPosition,
                'Unsupported Local Binding Position',
                'By-reference foreach targets are not supported in Stage 5.',
                $this->createNodeSpan($foreach->valueVar),
            );
        }

        if ($foreach->keyVar instanceof Expr) {
            $binding = $this->processForeachBinding($foreach->keyVar, ForeachBindingPosition::Key, $keyType, $scope);

            if ($binding !== null) {
                $declaredBindings[] = $binding;
            }
        }

        $binding = $this->processForeachBinding($foreach->valueVar, ForeachBindingPosition::Value, $valueType, $scope);

        if ($binding !== null) {
            $declaredBindings[] = $binding;
        }

        foreach ($foreach->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        foreach ($declaredBindings as $declaredBinding) {
            $declaredBinding->markMaybeUninitialized();
        }
    }

    private function processFor(Stmt\For_ $for, Scope $scope): void
    {
        foreach ($for->init as $initializer) {
            $this->processNode($initializer, $scope);
        }

        foreach ($for->cond as $condition) {
            $this->processNode($condition, $scope);
        }

        foreach ($for->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        foreach ($for->loop as $update) {
            $this->processNode($update, $scope);
        }
    }

    private function processIf(Stmt\If_ $if, Scope $scope): void
    {
        $guarded = $this->resolvePositiveIssetBinding($if->cond, $scope);

        if ($guarded === null) {
            $this->processNode($if->cond, $scope);
        } else {
            $guarded->recordRead($this->createNodeSpan($if->cond));
            $guarded->markInitialized();
        }

        foreach ($if->stmts as $statement) {
            $this->processNode($statement, $scope);
        }

        if ($guarded !== null) {
            $guarded->markMaybeUninitialized();
        }

        foreach ($if->elseifs as $elseif) {
            $this->processNode($elseif->cond, $scope);

            foreach ($elseif->stmts as $statement) {
                $this->processNode($statement, $scope);
            }
        }

        $elseStatements = $if->else === null ? [] : $if->else->stmts;

        foreach ($elseStatements as $statement) {
            $this->processNode($statement, $scope);
        }
    }

    private function resolvePositiveIssetBinding(Expr $condition, Scope $scope): ?LocalBinding
    {
        if (!$condition instanceof Expr\Isset_ || count($condition->vars) !== 1) {
            return null;
        }

        $variable = $condition->vars[0];

        if (!$variable instanceof Expr\Variable) {
            return null;
        }

        $name = $this->resolveVariableName($variable);
        $binding = $name === null ? null : $scope->resolve($name)?->binding;

        return $binding?->initialization === BindingInitialization::MaybeUninitialized
            ? $binding
            : null;
    }

    private function processForeachBinding(
        Expr $target,
        ForeachBindingPosition $position,
        LocalType $assignedType,
        Scope $scope,
    ): ?LocalBinding {
        $declaration = $target instanceof Expr\Variable
            ? $this->foreachBindingsByVariableOffset[$target->getStartFilePos()] ?? null
            : null;

        if ($declaration === null) {
            $this->processIterationTarget($target, $scope, $assignedType);

            return null;
        }

        if ($declaration->position !== $position) {
            $this->addDiagnostic(
                DiagnosticCode::InternalCompilerError,
                'Internal Compiler Error',
                'A typed foreach binding was associated with the wrong header position.',
                $declaration->span,
            );

            return null;
        }

        $name = $this->resolveVariableName($target);

        if ($name === null) {
            return null;
        }

        $declaredType = LocalType::createFromSourceType($declaration->type);

        if (!$declaredType->equalsCanonical($assignedType)) {
            $this->addDiagnostic(
                DiagnosticCode::LoopBindingTypeDoesNotMatch,
                'Loop Binding Type Does Not Match',
                sprintf('The %s binding type %s must exactly match the collection contract %s.', $position->value, $declaredType->text, $assignedType->text),
                $declaration->type->span,
            );
        }

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                'Duplicate Local Declaration',
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $this->resolveDeclarationLabels($existing),
            );

            return null;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            BindingMutability::Mutable,
            $declaration->span,
            $declaration->variableSpan,
            null,
            null,
            $assignedType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            BindingMutability::Mutable,
            $declaration->variableSpan,
            $binding,
        ));

        return $binding;
    }

    private function processIterationTarget(Expr $target, Scope $scope, ?LocalType $assignedType = null): void
    {
        if ($target instanceof Expr\Variable) {
            $name = $this->resolveVariableName($target);
            $symbol = $name === null ? null : $scope->resolve($name);
            $span = $this->createNodeSpan($target);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::UnsupportedLocalBindingPosition,
                    'Unsupported Local Binding Position',
                    sprintf('Foreach target %s must be an existing mutable local variable.', $name ?? 'variable'),
                    $span,
                );

                return;
            }

            if ($symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeMutated,
                    'Readonly Local Cannot Be Mutated',
                    sprintf('%s cannot be used as a foreach target because it is readonly.', $symbol->name),
                    $span,
                    $symbol,
                );

                return;
            }

            if ($assignedType !== null && !$this->compatibility->accepts($symbol->type, $assignedType)) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                    'Assignment Is Not Assignable To Declared Type',
                    sprintf('The loop value of type %s is not assignable to %s of type %s.', $assignedType->text, $symbol->name, $symbol->type->text),
                    $span,
                    $this->resolveDeclarationLabels($symbol),
                );
            }

            $symbol->binding?->recordWrite($span);

            return;
        }

        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->processIterationTarget($item->value, $scope, $assignedType);
                }
            }

            return;
        }

        $this->addDiagnostic(
            DiagnosticCode::UnsupportedLocalBindingPosition,
            'Unsupported Local Binding Position',
            'This foreach target is not supported by the Stage 5 binding model.',
            $this->createNodeSpan($target),
        );
    }

    private function processAssignment(Expr\Assign $assignment, Scope $scope): void
    {
        $forDeclaration = $assignment->var instanceof Expr\Variable
            ? $this->resolveTypedForInitializer($assignment)
            : null;

        if ($forDeclaration !== null) {
            $this->processTypedForInitializer($forDeclaration, $assignment, $scope);

            return;
        }

        $declaration = $assignment->var instanceof Expr\Variable
            ? $this->resolveTypedDeclaration($assignment)
            : null;

        if ($declaration !== null) {
            $this->processTypedDeclaration($declaration, $assignment, $scope);

            return;
        }

        $this->processNode($assignment->expr, $scope);
        $this->processAssignmentTarget($assignment->var, $assignment->expr, $scope);
    }

    private function processTypedForInitializer(
        TypedForInitializer $declaration,
        Expr\Assign $assignment,
        Scope $scope,
    ): void {
        $name = $this->resolveVariableName($assignment->var);

        if ($name === null) {
            return;
        }

        $this->processNode($assignment->expr, $scope);
        $initializerType = $this->expressionTypes->resolve($assignment->expr, $scope);
        $declaredType = LocalType::createFromSourceType($declaration->type);

        if (!$this->compatibility->accepts($declaredType, $initializerType)) {
            $this->addDiagnostic(
                DiagnosticCode::InitializerNotAssignableToDeclaredType,
                'Initializer Is Not Assignable To Declared Type',
                sprintf('Initializer of type %s is not assignable to declared type %s.', $initializerType->text, $declaredType->text),
                $declaration->initializerSpan,
                [new DiagnosticLabel($declaration->type->span, 'The local type is declared here.')],
            );
        }

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                'Duplicate Local Declaration',
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $this->resolveDeclarationLabels($existing),
            );

            return;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
            $declaration->span,
            $declaration->variableSpan,
            $declaration->initializerSpan,
            $assignment->expr,
            $initializerType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            $binding->mutability,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function processTypedDeclaration(
        TypedLocalDeclaration $declaration,
        Expr\Assign $assignment,
        Scope $scope,
    ): void {
        $name = $this->resolveVariableName($assignment->var);

        if ($name === null) {
            $this->addInternalAssociationDiagnostic($declaration);

            return;
        }

        $this->processNode($assignment->expr, $scope);
        $initializerType = $this->expressionTypes->resolve($assignment->expr, $scope);
        $declaredType = LocalType::createFromSourceType($declaration->type);

        if (!$this->compatibility->accepts($declaredType, $initializerType)) {
            $this->addDiagnostic(
                DiagnosticCode::InitializerNotAssignableToDeclaredType,
                'Initializer Is Not Assignable To Declared Type',
                sprintf('Initializer of type %s is not assignable to declared type %s.', $initializerType->text, $declaredType->text),
                $declaration->initializerSpan,
                [new DiagnosticLabel($declaration->type->span, 'The local type is declared here.')],
            );
        }

        $existing = $scope->resolve($name);

        if ($existing !== null) {
            $related = $existing->declarationSpan === null
                ? []
                : [new DiagnosticLabel($existing->declarationSpan, 'The existing binding is declared here.')];
            $this->addDiagnostic(
                DiagnosticCode::DuplicateLocalDeclaration,
                'Duplicate Local Declaration',
                sprintf('%s is already declared in this variable scope.', $name),
                $declaration->variableSpan,
                $related,
            );

            return;
        }

        $binding = new LocalBinding(
            $declaration->id,
            $name,
            $declaredType,
            $declaration->readonlySpan === null ? BindingMutability::Mutable : BindingMutability::Readonly,
            $declaration->span,
            $declaration->variableSpan,
            $declaration->initializerSpan,
            $assignment->expr,
            $initializerType,
        );
        $binding->recordWrite($declaration->variableSpan);
        $this->context->model->bindings->record($binding);
        $scope->declare(new VariableSymbol(
            $name,
            $declaredType,
            $binding->mutability,
            $declaration->variableSpan,
            $binding,
        ));
    }

    private function processAssignmentTarget(Expr $target, Expr $value, Scope $scope): void
    {
        if ($target instanceof Expr\Variable) {
            $name = $this->resolveVariableName($target);
            $symbol = $name === null ? null : $scope->resolve($name);
            $span = $this->createNodeSpan($target);

            if ($symbol === null) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentCannotDeclareVariable,
                    'Assignment Cannot Declare Variable',
                    sprintf('%s must be declared with an explicit type before it can be assigned.', $name ?? 'The variable'),
                    $span,
                );

                return;
            }

            if ($symbol->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                    'Readonly Local Cannot Be Reassigned',
                    sprintf('%s cannot be assigned after its declaration.', $symbol->name),
                    $span,
                    $symbol,
                );

                return;
            }

            $actualType = $this->expressionTypes->resolve($value, $scope);

            if (!$this->compatibility->accepts($symbol->type, $actualType)) {
                $this->addDiagnostic(
                    DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                    'Assignment Is Not Assignable To Declared Type',
                    sprintf('Value of type %s is not assignable to %s of type %s.', $actualType->text, $symbol->name, $symbol->type->text),
                    $this->createNodeSpan($value),
                    $this->resolveDeclarationLabels($symbol),
                );
            }

            $symbol->binding?->recordWrite($span);
            $symbol->binding?->markInitialized();

            return;
        }

        if ($target instanceof Expr\ArrayDimFetch) {
            $this->processStructuralWrite($target, $scope);

            return;
        }

        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->processIterationTarget($item->value, $scope);
                }
            }

            return;
        }

        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) {
            $this->processNode($target->var, $scope);

            if ($target->name instanceof Expr) {
                $this->processNode($target->name, $scope);
            }

            return;
        }

        $this->processNode($target, $scope);
    }

    private function processCompoundAssignment(Expr\AssignOp $assignment, Scope $scope): void
    {
        $this->processNode($assignment->expr, $scope);

        if (!$assignment->var instanceof Expr\Variable) {
            if ($assignment->var instanceof Expr\ArrayDimFetch) {
                $this->processStructuralWrite($assignment->var, $scope);
            } else {
                $this->processNode($assignment->var, $scope);
            }

            return;
        }

        $name = $this->resolveVariableName($assignment->var);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($assignment->var);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                'Local Variable Is Not Declared',
                sprintf('%s must be declared before it can be updated.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                'Readonly Local Cannot Be Reassigned',
                sprintf('%s cannot be updated because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        $resultType = $this->resolveCompoundAssignmentType($assignment, $symbol, $scope);

        if (!$this->compatibility->accepts($symbol->type, $resultType)) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                'Assignment Is Not Assignable To Declared Type',
                sprintf('The compound assignment produces %s, which is not assignable to %s.', $resultType->text, $symbol->type->text),
                $this->createNodeSpan($assignment),
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processIncrementOrDecrement(Expr $target, Scope $scope): void
    {
        if (!$target instanceof Expr\Variable) {
            if ($target instanceof Expr\ArrayDimFetch) {
                $this->processStructuralWrite($target, $scope);
            } else {
                $this->processNode($target, $scope);
            }

            return;
        }

        $name = $this->resolveVariableName($target);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($target);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                'Local Variable Is Not Declared',
                sprintf('%s must be declared before it can be updated.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                'Readonly Local Cannot Be Reassigned',
                sprintf('%s cannot be updated because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        if (!$symbol->type->includes('int') && !$symbol->type->includes('float')) {
            $this->addDiagnostic(
                DiagnosticCode::AssignmentNotAssignableToDeclaredType,
                'Assignment Is Not Assignable To Declared Type',
                sprintf('Increment and decrement require a numeric local, but %s has type %s.', $symbol->name, $symbol->type->text),
                $span,
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processStructuralWrite(Expr\ArrayDimFetch $target, Scope $scope): void
    {
        if ($target->dim instanceof Expr) {
            $this->processNode($target->dim, $scope);
        }

        $root = $this->resolveRootVariable($target);

        if ($root === null) {
            $this->processNode($target->var, $scope);

            return;
        }

        $name = $this->resolveVariableName($root);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($root);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                'Local Variable Is Not Declared',
                sprintf('%s must be declared before its structure can be changed.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeMutated,
                'Readonly Local Cannot Be Mutated',
                sprintf('%s cannot be mutated because it is readonly.', $symbol->name),
                $this->createNodeSpan($target),
                $symbol,
            );

            return;
        }

        $symbol->binding?->recordRead($span);
        $symbol->binding?->recordWrite($span);
    }

    private function processUnsetTarget(Expr $target, Scope $scope): void
    {
        if ($target instanceof Expr\ArrayDimFetch) {
            $this->processStructuralWrite($target, $scope);

            return;
        }

        if (!$target instanceof Expr\Variable) {
            $this->processNode($target, $scope);

            return;
        }

        $name = $this->resolveVariableName($target);
        $symbol = $name === null ? null : $scope->resolve($name);
        $span = $this->createNodeSpan($target);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                'Local Variable Is Not Declared',
                sprintf('%s must be declared before it can be unset.', $name ?? 'The variable'),
                $span,
            );

            return;
        }

        if ($symbol->mutability === BindingMutability::Readonly) {
            $this->addReadonlyDiagnostic(
                DiagnosticCode::ReadonlyLocalCannotBeReassigned,
                'Readonly Local Cannot Be Reassigned',
                sprintf('%s cannot be unset because it is readonly.', $symbol->name),
                $span,
                $symbol,
            );

            return;
        }

        $symbol->binding?->recordWrite($span);
    }

    private function processReferenceAssignment(Expr\AssignRef $assignment, Scope $scope): void
    {
        $this->processNode($assignment->expr, $scope);
        $source = $this->resolveRootVariable($assignment->expr);

        if ($source !== null) {
            $name = $this->resolveVariableName($source);
            $symbol = $name === null ? null : $scope->resolve($name);

            if ($symbol?->mutability === BindingMutability::Readonly) {
                $this->addReadonlyDiagnostic(
                    DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                    'Readonly Local Cannot Be Referenced',
                    sprintf('%s cannot be used to create a reference because it is readonly.', $symbol->name),
                    $this->createNodeSpan($source),
                    $symbol,
                );
            }
        }

        $this->addDiagnostic(
            DiagnosticCode::UnsupportedLocalBindingPosition,
            'Unsupported Local Binding Position',
            'Explicit reference creation is not supported in Stage 5.',
            $this->createNodeSpan($assignment),
        );

        $this->processAssignmentTarget($assignment->var, $assignment->expr, $scope);
    }

    private function processFunctionCall(Expr\FuncCall $call, Scope $scope): void
    {
        $byReferencePositions = $call->name instanceof Node\Name
            ? $this->context->callableSignatures->resolveFunction($call->name->toString())
            : null;

        if ($call->name instanceof Node\Name && in_array(strtolower($call->name->toString()), self::MUTATING_FUNCTIONS, true)) {
            $firstArgument = $call->args[0] ?? null;

            if ($firstArgument instanceof Arg) {
                $root = $this->resolveRootVariable($firstArgument->value);

                if ($root !== null) {
                    $name = $this->resolveVariableName($root);
                    $symbol = $name === null ? null : $scope->resolve($name);

                    if ($symbol?->mutability === BindingMutability::Readonly) {
                        $this->addReadonlyDiagnostic(
                            DiagnosticCode::ReadonlyLocalCannotBeMutated,
                            'Readonly Local Cannot Be Mutated',
                            sprintf('%s cannot be passed to a mutating function because it is readonly.', $symbol->name),
                            $this->createNodeSpan($firstArgument->value),
                            $symbol,
                        );
                    }
                }
            }
        }

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $byReferencePositions, $scope);
    }

    private function processStaticCall(Expr\StaticCall $call, Scope $scope): void
    {
        $byReferencePositions = $call->class instanceof Node\Name && $call->name instanceof Node\Identifier
            ? $this->context->callableSignatures->resolveMethod($call->class->toString(), $call->name->toString())
            : null;

        if ($call->class instanceof Expr) {
            $this->processNode($call->class, $scope);
        }

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $byReferencePositions, $scope);
    }

    private function processMethodCall(
        Expr\MethodCall|Expr\NullsafeMethodCall $call,
        Scope $scope,
    ): void {
        $className = null;

        if ($call->var instanceof Expr\Variable) {
            $name = $this->resolveVariableName($call->var);
            $className = $name === null ? null : $scope->resolve($name)?->type->resolveSingleNamedType();
        }

        $byReferencePositions = $className !== null && $call->name instanceof Node\Identifier
            ? $this->context->callableSignatures->resolveMethod($className, $call->name->toString())
            : null;

        $this->processNode($call->var, $scope);

        if ($call->name instanceof Expr) {
            $this->processNode($call->name, $scope);
        }

        $this->processCallArguments($call->args, $byReferencePositions, $scope);
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $arguments
     * @param list<int>|null $byReferencePositions
     */
    private function processCallArguments(
        array $arguments,
        ?array $byReferencePositions,
        Scope $scope,
    ): void {
        foreach ($arguments as $position => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            if ($byReferencePositions !== null && in_array($position, $byReferencePositions, true)) {
                $root = $this->resolveRootVariable($argument->value);

                if ($root !== null) {
                    $name = $this->resolveVariableName($root);
                    $symbol = $name === null ? null : $scope->resolve($name);
                    $span = $this->createNodeSpan($argument->value);

                    if ($symbol?->mutability === BindingMutability::Readonly) {
                        $this->addReadonlyDiagnostic(
                            DiagnosticCode::ReadonlyLocalCannotBeReferenced,
                            'Readonly Local Cannot Be Referenced',
                            sprintf('%s cannot be passed to a by-reference parameter because it is readonly.', $symbol->name),
                            $span,
                            $symbol,
                        );
                    } else {
                        $symbol?->binding?->recordWrite($span);
                    }
                }
            }

            $this->processNode($argument->value, $scope);
        }
    }

    private function processVariableRead(Expr\Variable $variable, Scope $scope): void
    {
        $name = $this->resolveVariableName($variable);

        if ($name === null) {
            return;
        }

        $symbol = $scope->resolve($name);
        $span = $this->createNodeSpan($variable);

        if ($symbol === null) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableNotDeclared,
                'Local Variable Is Not Declared',
                sprintf('%s is read before an explicit declaration is in scope.', $name),
                $span,
            );

            return;
        }

        if ($symbol->initialization === BindingInitialization::MaybeUninitialized) {
            $this->addDiagnostic(
                DiagnosticCode::LocalVariableMayBeUninitialized,
                'Local Variable May Be Uninitialized',
                sprintf('%s may be uninitialized because its foreach loop may execute zero times.', $name),
                $span,
                $this->resolveDeclarationLabels($symbol),
            );
        }

        $symbol->binding?->recordRead($span);
    }

    /** @param array<Param> $parameters */
    private function declareParameters(Scope $scope, array $parameters): void
    {
        foreach ($parameters as $parameter) {
            if (!$parameter->var instanceof Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $scope->declare(new VariableSymbol(
                '$' . $parameter->var->name,
                $this->resolveDeclaredPhpType($parameter->type),
                BindingMutability::Mutable,
                $this->createNodeSpan($parameter->var),
            ));
        }
    }

    private function createScope(string $kind): Scope
    {
        $scope = new Scope($kind);

        foreach ([
            '$GLOBALS' => 'array',
            '$_SERVER' => 'array',
            '$_GET' => 'array',
            '$_POST' => 'array',
            '$_FILES' => 'array',
            '$_COOKIE' => 'array',
            '$_SESSION' => 'array',
            '$_REQUEST' => 'array',
            '$_ENV' => 'array',
            '$argc' => 'int',
            '$argv' => 'array',
        ] as $name => $type) {
            $scope->declare(new VariableSymbol(
                $name,
                LocalType::createAtomic($type),
                BindingMutability::Mutable,
            ));
        }

        return $scope;
    }

    private function resolveDeclaredPhpType(Node\Identifier|Node\Name|Node\ComplexType|null $type): LocalType
    {
        if ($type === null) {
            return LocalType::createUnknown();
        }

        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return LocalType::createFromText($type->toString());
        }

        if ($type instanceof Node\NullableType) {
            $inner = $type->type->toString();

            return LocalType::createFromText('?' . $inner);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $separator = $type instanceof Node\UnionType ? '|' : '&';
            $parts = [];

            foreach ($type->types as $part) {
                if ($part instanceof Node\Identifier || $part instanceof Node\Name) {
                    $parts[] = $part->toString();
                }
            }

            return $parts === [] ? LocalType::createUnknown() : LocalType::createFromText(implode($separator, $parts));
        }

        return LocalType::createUnknown();
    }

    private function resolveTypedDeclaration(Expr\Assign $assignment): ?TypedLocalDeclaration
    {
        $variableStart = $assignment->var->getStartFilePos();
        $declaration = $this->declarationsByVariableOffset[$variableStart] ?? null;

        if ($declaration === null) {
            return null;
        }

        $initializerStart = $assignment->expr->getStartFilePos();
        $initializerEnd = $assignment->expr->getEndFilePos() + 1;

        if (
            $declaration->initializerSpan->start->offset !== $initializerStart
            || $declaration->initializerSpan->end->offset !== $initializerEnd
        ) {
            $this->addInternalAssociationDiagnostic($declaration);

            return null;
        }

        return $declaration;
    }

    private function resolveTypedForInitializer(Expr\Assign $assignment): ?TypedForInitializer
    {
        $variableStart = $assignment->var->getStartFilePos();
        $declaration = $this->forInitializersByVariableOffset[$variableStart] ?? null;

        if ($declaration === null) {
            return null;
        }

        if (
            $declaration->initializerSpan->start->offset !== $assignment->expr->getStartFilePos()
            || $declaration->initializerSpan->end->offset !== $assignment->expr->getEndFilePos() + 1
        ) {
            $this->addDiagnostic(
                DiagnosticCode::InternalCompilerError,
                'Internal Compiler Error',
                'The normalized PHP assignment could not be associated with its typed for initializer.',
                $declaration->span,
            );

            return null;
        }

        return $declaration;
    }

    private function addInternalAssociationDiagnostic(TypedLocalDeclaration $declaration): void
    {
        $this->addDiagnostic(
            DiagnosticCode::InternalCompilerError,
            'Internal Compiler Error',
            'The normalized PHP assignment could not be associated with its typed local declaration.',
            $declaration->span,
        );
    }

    private function resolveCompoundAssignmentType(
        Expr\AssignOp $assignment,
        VariableSymbol $symbol,
        Scope $scope,
    ): LocalType {
        if ($assignment instanceof Expr\AssignOp\Concat) {
            return LocalType::createAtomic('string');
        }

        if ($assignment instanceof Expr\AssignOp\Div) {
            return LocalType::createAtomic('float');
        }

        $right = $this->expressionTypes->resolve($assignment->expr, $scope);

        if ($assignment instanceof Expr\AssignOp\Plus && $symbol->type->includes('array') && $right->includes('array')) {
            return LocalType::createAtomic('array');
        }

        if (
            $assignment instanceof Expr\AssignOp\Plus
            || $assignment instanceof Expr\AssignOp\Minus
            || $assignment instanceof Expr\AssignOp\Mul
            || $assignment instanceof Expr\AssignOp\Pow
            || $assignment instanceof Expr\AssignOp\Mod
        ) {
            if ($symbol->type->includes('float') || $right->includes('float')) {
                return LocalType::createAtomic('float');
            }

            if ($symbol->type->includes('int') && $right->includes('int')) {
                return LocalType::createAtomic('int');
            }
        }

        return LocalType::createUnknown();
    }

    private function resolveRootVariable(Expr $expression): ?Expr\Variable
    {
        while ($expression instanceof Expr\ArrayDimFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Expr\Variable ? $expression : null;
    }

    private function resolveVariableName(Expr $variable): ?string
    {
        return $variable instanceof Expr\Variable && is_string($variable->name)
            ? '$' . $variable->name
            : null;
    }

    private function createNodeSpan(Node $node): Span
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return $this->context->parsedFile->sourceFile->createSpan(0, 0);
        }

        return $this->context->parsedFile->sourceFile->createSpan($start, $end + 1);
    }

    /** @return list<DiagnosticLabel> */
    private function resolveDeclarationLabels(VariableSymbol $symbol): array
    {
        return $symbol->declarationSpan === null
            ? []
            : [new DiagnosticLabel($symbol->declarationSpan, sprintf('%s is declared here.', $symbol->name))];
    }

    private function addReadonlyDiagnostic(
        DiagnosticCode $code,
        string $title,
        string $message,
        Span $span,
        VariableSymbol $symbol,
    ): void {
        $this->addDiagnostic($code, $title, $message, $span, $this->resolveDeclarationLabels($symbol));
    }

    /** @param list<DiagnosticLabel> $related */
    private function addDiagnostic(
        DiagnosticCode $code,
        string $title,
        string $message,
        Span $span,
        array $related = [],
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            new DiagnosticLabel($span, $message),
            $related,
        ));
    }

    private function enterScope(Scope $scope): void
    {
        $this->context->scopes->push($scope);
    }

    private function leaveScope(): void
    {
        $this->context->scopes->pop();
    }
}
