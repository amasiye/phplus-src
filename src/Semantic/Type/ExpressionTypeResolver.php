<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Scope\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

final readonly class ExpressionTypeResolver
{
    public function resolve(Expr $expression, Scope $scope): LocalType
    {
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
            return LocalType::createAtomic($expression->class->toString());
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $scope->resolve('$' . $expression->name)->type ?? LocalType::createUnknown();
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
