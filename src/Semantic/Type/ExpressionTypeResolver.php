<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Scope\Scope;
use Amasiye\Ppphp\Semantic\SemanticContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

final class ExpressionTypeResolver
{
    private readonly SourceTypeResolver $sourceTypes;

    private readonly ?MemberTypeResolver $members;

    public function __construct(
        private readonly ?SemanticContext $context = null,
        ?SourceTypeResolver $sourceTypes = null,
    ) {
        $this->sourceTypes = $sourceTypes ?? new SourceTypeResolver();
        $this->members = $context === null ? null : new MemberTypeResolver($context->symbols);
    }

    public function resolve(Expr $expression, Scope $scope): LocalType
    {
        if (is_string($expression->getAttribute('ppphpWhenExpressionId'))) {
            return LocalType::createUnknown();
        }

        if ($expression instanceof Scalar\Int_) {
            return LocalType::createAtomic('int');
        }

        if ($expression instanceof Scalar\Float_) {
            return LocalType::createAtomic('float');
        }

        if ($expression instanceof Scalar\String_) {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\Array_) {
            return LocalType::createAtomic('array');
        }

        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            return LocalType::createAtomic('callable');
        }

        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'null' => LocalType::createAtomic('null'),
                'true' => LocalType::createAtomic('true'),
                'false' => LocalType::createAtomic('false'),
                default => LocalType::createUnknown(),
            };
        }

        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            return $this->context === null
                ? LocalType::createAtomic($expression->class->toString())
                : LocalType::createFromSemanticType($this->sourceTypes->resolveNode(
                    $expression->class,
                    $this->context->parsedFile,
                    $this->context->resolvedNames,
                    $this->context->genericDeclarations,
                ));
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->resolve('$' . $expression->name)->type ?? LocalType::createUnknown();
        }

        if ($expression instanceof Expr\ArrayDimFetch) {
            $container = $this->resolve($expression->var, $scope)->semanticType;
            $valueType = $this->resolveArrayValueType($container);

            return $valueType === null
                ? LocalType::createUnknown()
                : LocalType::createFromSemanticType($valueType);
        }

        if (
            ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch)
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
        ) {
            return LocalType::createFromSemanticType($this->members->resolvePropertyType(
                $this->resolve($expression->var, $scope)->semanticType,
                $expression->name->toString(),
                $expression instanceof Expr\NullsafePropertyFetch,
            ));
        }

        if (
            ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall)
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
        ) {
            return LocalType::createFromSemanticType($this->members->resolveMethodReturnType(
                $this->resolve($expression->var, $scope)->semanticType,
                $expression->name->toString(),
                $expression instanceof Expr\NullsafeMethodCall,
            ));
        }

        if (
            $expression instanceof Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
            && $this->members !== null
            && $this->context !== null
        ) {
            $receiver = $this->sourceTypes->resolveNode(
                $expression->class,
                $this->context->parsedFile,
                $this->context->resolvedNames,
                $this->context->genericDeclarations,
            );

            return LocalType::createFromSemanticType(
                $this->members->resolveMethodReturnType($receiver, $expression->name->toString()),
            );
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            return $this->resolveFunctionCall($expression, $scope);
        }

        if ($expression instanceof Expr\Cast\Int_) {
            return LocalType::createAtomic('int');
        }

        if ($expression instanceof Expr\Cast\Double) {
            return LocalType::createAtomic('float');
        }

        if ($expression instanceof Expr\Cast\String_) {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\Cast\Bool_) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\Cast\Array_) {
            return LocalType::createAtomic('array');
        }

        if ($expression instanceof Expr\Cast\Object_) {
            return LocalType::createAtomic('object');
        }

        if ($expression instanceof Expr\BooleanNot || $expression instanceof Expr\Instanceof_) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            $operand = $this->resolve($expression->expr, $scope);

            if ($operand->includes('int')) {
                return LocalType::createAtomic('int');
            }

            if ($operand->includes('float')) {
                return LocalType::createAtomic('float');
            }

            return LocalType::createUnknown();
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return LocalType::createAtomic('string');
        }

        if (
            $expression instanceof Expr\BinaryOp\Equal
            || $expression instanceof Expr\BinaryOp\Greater
            || $expression instanceof Expr\BinaryOp\GreaterOrEqual
            || $expression instanceof Expr\BinaryOp\Identical
            || $expression instanceof Expr\BinaryOp\LogicalAnd
            || $expression instanceof Expr\BinaryOp\LogicalOr
            || $expression instanceof Expr\BinaryOp\LogicalXor
            || $expression instanceof Expr\BinaryOp\NotEqual
            || $expression instanceof Expr\BinaryOp\NotIdentical
            || $expression instanceof Expr\BinaryOp\Smaller
            || $expression instanceof Expr\BinaryOp\SmallerOrEqual
            || $expression instanceof Expr\BinaryOp\BooleanAnd
            || $expression instanceof Expr\BinaryOp\BooleanOr
        ) {
            return LocalType::createAtomic('bool');
        }

        if ($expression instanceof Expr\BinaryOp\Spaceship) {
            return LocalType::createAtomic('int');
        }

        if (
            $expression instanceof Expr\BinaryOp\Plus
            || $expression instanceof Expr\BinaryOp\Minus
            || $expression instanceof Expr\BinaryOp\Mul
            || $expression instanceof Expr\BinaryOp\Pow
            || $expression instanceof Expr\BinaryOp\Mod
            || $expression instanceof Expr\BinaryOp\Div
        ) {
            return $this->resolveNumericBinaryOperation($expression, $scope);
        }

        if ($expression instanceof Expr\ClassConstFetch && $expression->name instanceof Node\Identifier && strtolower($expression->name->name) === 'class') {
            return LocalType::createAtomic('string');
        }

        if ($expression instanceof Expr\Ternary) {
            $true = $expression->if === null
                ? $this->resolve($expression->cond, $scope)
                : $this->resolve($expression->if, $scope);
            $false = $this->resolve($expression->else, $scope);

            if ($true->unknown || $false->unknown) {
                return LocalType::createUnknown();
            }

            return LocalType::createFromText($true->text . '|' . $false->text);
        }

        return LocalType::createUnknown();
    }

    private function resolveFunctionCall(Expr\FuncCall $call, Scope $scope): LocalType
    {
        if (!$call->name instanceof Node\Name) {
            return LocalType::createUnknown();
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));
        $collection = $call->args[0] ?? null;

        if (($name === 'array_filter' || $name === 'array_values') && $collection instanceof Node\Arg) {
            $input = $this->resolve($collection->value, $scope)->semanticType;
            $transformed = $this->resolveCollectionFunction($name, $input);

            if ($transformed !== null) {
                return LocalType::createFromSemanticType($transformed);
            }
        }

        if ($this->context === null) {
            return LocalType::createUnknown();
        }

        $resolved = $this->context->resolvedNames->resolve($call->name) ?? $call->name->toString();
        $symbol = $this->context->symbols->findFunction($resolved)
            ?? $this->context->symbols->findFunction($call->name->toString());

        return $symbol?->returnType === null
            ? LocalType::createUnknown()
            : LocalType::createFromSemanticType($symbol->returnType->semanticType);
    }

    private function resolveCollectionFunction(string $name, Interfaces\Type $type): ?Interfaces\Type
    {
        if ($type instanceof UnionType) {
            $members = [];

            foreach ($type->members as $member) {
                if ($member instanceof AtomicType && $member->canonical === 'null') {
                    continue;
                }

                $resolved = $this->resolveCollectionFunction($name, $member);

                if ($resolved === null) {
                    return null;
                }

                $members[] = $resolved;
            }

            if ($members === []) {
                return null;
            }

            return $this->members?->combine($members)
                ?? (count($members) === 1 ? $members[0] : new UnionType($members));
        }

        if (!$type instanceof TypedArrayType) {
            return null;
        }

        return $name === 'array_values'
            ? new TypedArrayType(new AtomicType('int'), $type->valueType, true)
            : new TypedArrayType($type->keyType, $type->valueType, false);
    }

    private function resolveArrayValueType(Interfaces\Type $type): ?Interfaces\Type
    {
        if ($type instanceof TypedArrayType) {
            return $type->valueType;
        }

        if (!$type instanceof UnionType) {
            return null;
        }

        $members = [];

        foreach ($type->members as $member) {
            $valueType = $this->resolveArrayValueType($member);

            if ($valueType !== null) {
                $members[$valueType->canonical] = $valueType;
            }
        }

        if ($members === []) {
            return null;
        }

        return count($members) === 1 ? reset($members) : new UnionType(array_values($members));
    }

    private function resolveNumericBinaryOperation(Expr\BinaryOp $expression, Scope $scope): LocalType
    {
        $left = $this->resolve($expression->left, $scope);
        $right = $this->resolve($expression->right, $scope);

        if ($expression instanceof Expr\BinaryOp\Div) {
            return $left->unknown || $right->unknown
                ? LocalType::createUnknown()
                : LocalType::createAtomic('float');
        }

        if ($left->includes('float') || $right->includes('float')) {
            return LocalType::createAtomic('float');
        }

        if ($left->includes('int') && $right->includes('int')) {
            return LocalType::createAtomic('int');
        }

        return LocalType::createUnknown();
    }
}
