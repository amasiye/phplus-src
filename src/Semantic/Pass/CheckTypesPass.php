<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Semantic\NodeSpanResolver;
use Amasiye\Ppphp\Semantic\Pass\Interfaces\SemanticPass;
use Amasiye\Ppphp\Semantic\SemanticContext;
use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

final class CheckTypesPass implements SemanticPass
{
    private SemanticContext $context;

    public function __construct(private readonly NodeSpanResolver $spans = new NodeSpanResolver()) {}

    public function execute(SemanticContext $context): void
    {
        $this->context = $context;

        foreach ($context->parsedFile->statements as $statement) {
            $this->checkNode($statement);
        }
    }

    private function checkNode(Node $node, ?ClassSymbol $class = null): void
    {
        if ($node instanceof Stmt\ClassLike) {
            $name = $node->name === null ? null : $this->resolveDeclaredClassName($node);
            $class = $name === null ? null : $this->context->symbols->findClass($name);
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->checkCallable($node);
        }

        if ($node instanceof Stmt\Property && $node->type === null) {
            foreach ($node->props as $property) {
                $this->addDiagnostic(
                    DiagnosticCode::MissingPropertyType,
                    'Missing Property Type',
                    sprintf('Property $%s requires an explicit native type in ++PHP.', $property->name->toString()),
                    $this->spans->resolve($this->context->parsedFile, $property),
                    'Add a native property type. Use mixed when the broad type is deliberate.',
                );
            }
        }

        if ($node instanceof Expr\Eval_) {
            $this->addUnsafe('eval is not allowed in ++PHP.', $node);
        }

        if ($node instanceof Expr\Variable && !is_string($node->name)) {
            $this->addUnsafe('Variable variables are not allowed in ++PHP.', $node);
        }

        if ($node instanceof Expr\Include_ && !$this->isStaticPath($node->expr)) {
            $this->addUnsafe('Include and require paths in ++PHP must be statically known.', $node);
        }

        if ($node instanceof Expr\Assign && $node->var instanceof Expr\PropertyFetch) {
            $this->checkPropertyAssignment($node->var, $class);
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $value = $node->{$subNodeName};

            if ($value instanceof Node) {
                $this->checkNode($value, $class);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->checkNode($child, $class);
                    }
                }
            }
        }
    }

    /** @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $callable */
    private function checkCallable(Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $callable): void
    {
        foreach ($callable->params as $parameter) {
            if ($parameter->type !== null) {
                continue;
            }

            $name = $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)
                ? '$' . $parameter->var->name
                : 'The parameter';
            $this->addDiagnostic(
                DiagnosticCode::MissingParameterType,
                'Missing Parameter Type',
                sprintf('%s requires an explicit native type in ++PHP.', $name),
                $this->spans->resolve($this->context->parsedFile, $parameter),
                'Add a native parameter type. Use mixed when the broad type is deliberate.',
            );
        }

        $isLifecycleMethod = $callable instanceof Stmt\ClassMethod
            && in_array(strtolower($callable->name->toString()), ['__construct', '__destruct'], true);

        if ($callable->returnType === null && !$isLifecycleMethod) {
            $this->addDiagnostic(
                DiagnosticCode::MissingReturnType,
                'Missing Return Type',
                'Every callable in ++PHP requires an explicit native return type.',
                $this->spans->resolve($this->context->parsedFile, $callable),
                'Add a native return type. Use mixed, void, or never when that is the deliberate contract.',
            );
        }

        if ($callable->byRef) {
            $this->addUnsafe('Return-by-reference declarations are not allowed in ++PHP.', $callable);
        }
    }

    private function checkPropertyAssignment(Expr\PropertyFetch $property, ?ClassSymbol $class): void
    {
        if (!$property->name instanceof Node\Identifier) {
            $this->addUnsafe('Dynamically named property writes are not allowed in ++PHP.', $property);

            return;
        }

        if (
            $class === null
            || !$property->var instanceof Expr\Variable
            || $property->var->name !== 'this'
            || $class->findProperty($property->name->toString()) !== null
        ) {
            return;
        }

        $this->addDiagnostic(
            DiagnosticCode::DynamicPropertyNotAllowed,
            'Dynamic Property Is Not Allowed',
            sprintf('Property $%s is not declared on %s.', $property->name->toString(), $class->fullyQualifiedName),
            $this->spans->resolve($this->context->parsedFile, $property),
            'Declare the property with an explicit native type before assigning it.',
        );
    }

    private function isStaticPath(Expr $expression): bool
    {
        if ($expression instanceof Scalar\String_ || $expression instanceof Scalar\MagicConst\Dir || $expression instanceof Scalar\MagicConst\File) {
            return true;
        }

        return $expression instanceof Expr\BinaryOp\Concat
            && $this->isStaticPath($expression->left)
            && $this->isStaticPath($expression->right);
    }

    private function resolveDeclaredClassName(Stmt\ClassLike $class): ?string
    {
        $resolved = $class->name === null ? null : $this->context->resolvedNames->resolve($class->name);

        if ($resolved !== null) {
            return $resolved;
        }

        $span = $this->spans->resolve($this->context->parsedFile, $class);

        foreach ($this->context->symbols->classes as $symbol) {
            if (
                $symbol->sourceFile === $this->context->parsedFile->sourceFile
                && $symbol->declarationSpan->start->offset === $span->start->offset
            ) {
                return $symbol->fullyQualifiedName;
            }
        }

        return $class->name?->toString();
    }

    private function addUnsafe(string $message, Node $node): void
    {
        $this->addDiagnostic(
            DiagnosticCode::UnsafeDynamicConstruct,
            'Unsafe Dynamic Construct',
            $message,
            $this->spans->resolve($this->context->parsedFile, $node),
            'Replace the dynamic operation with a statically analyzable equivalent.',
        );
    }

    private function addDiagnostic(
        DiagnosticCode $code,
        string $title,
        string $message,
        Span $span,
        ?string $help = null,
    ): void {
        $this->context->model->diagnostics->add(new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            new DiagnosticLabel($span, $message),
            help: $help,
        ));
    }
}
