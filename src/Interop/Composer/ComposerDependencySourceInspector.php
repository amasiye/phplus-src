<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\SourceNameResolver;
use Amasiye\Ppphp\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

final readonly class ComposerDependencySourceInspector
{
    public function __construct(private SourceNameResolver $names = new SourceNameResolver()) {}

    public function inspect(ParsedFile $file): ComposerDependencyInspection
    {
        $includes = [];
        $conditional = [];
        $aliases = [];
        $dynamicInclude = false;
        $dynamicAlias = false;
        $this->inspectStatements(
            $file->statements,
            $file,
            '',
            $includes,
            $conditional,
            $aliases,
            $dynamicInclude,
            $dynamicAlias,
        );

        return new ComposerDependencyInspection(
            array_values(array_unique($includes)),
            $conditional,
            $aliases,
            $dynamicInclude,
            $dynamicAlias,
        );
    }

    /**
     * @param list<Stmt> $statements
     * @param list<string> $includes
     * @param list<Stmt> $conditional
     * @param array<string, string> $aliases
     */
    private function inspectStatements(
        array $statements,
        ParsedFile $file,
        string $namespace,
        array &$includes,
        array &$conditional,
        array &$aliases,
        bool &$dynamicInclude,
        bool &$dynamicAlias,
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->inspectStatements(
                    array_values($statement->stmts),
                    $file,
                    $statement->name?->toString() ?? '',
                    $includes,
                    $conditional,
                    $aliases,
                    $dynamicInclude,
                    $dynamicAlias,
                );
                continue;
            }

            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Include_) {
                $path = $this->includePath($statement->expr->expr, $file->sourceFile->path);

                if ($path === null) {
                    $dynamicInclude = true;
                } else {
                    $includes[] = $path;
                }

                continue;
            }

            if ($statement instanceof Stmt\Expression
                && $statement->expr instanceof Expr\FuncCall
                && $statement->expr->name instanceof Node\Name
                && strtolower($statement->expr->name->toString()) === 'class_alias') {
                $originalArgument = $statement->expr->args[0] ?? null;
                $aliasArgument = $statement->expr->args[1] ?? null;
                $original = $originalArgument instanceof Node\Arg
                    ? $this->className($originalArgument->value, $file)
                    : null;
                $alias = $aliasArgument instanceof Node\Arg
                    ? $this->className($aliasArgument->value, $file)
                    : null;

                if ($original === null || $alias === null) {
                    $dynamicAlias = true;
                } else {
                    $aliases[$alias] = $original;
                }

                continue;
            }

            if (!$statement instanceof Stmt\If_) {
                continue;
            }

            $guard = $this->negativeExistenceGuard($statement, $file);

            if ($guard === null) {
                continue;
            }

            [$kind, $guardedName] = $guard;
            $declarations = array_values(array_filter(
                $statement->stmts,
                static fn (Stmt $candidate): bool => $candidate instanceof Stmt\Function_ || $candidate instanceof Stmt\ClassLike,
            ));

            if (count($declarations) !== 1 || count($statement->stmts) !== 1) {
                continue;
            }

            $declaration = $declarations[0];
            $declaredKind = $declaration instanceof Stmt\Function_ ? 'function' : match (true) {
                $declaration instanceof Stmt\Interface_ => 'interface',
                $declaration instanceof Stmt\Trait_ => 'trait',
                $declaration instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $declaredName = $namespace === ''
                ? $declaration->name?->toString()
                : $namespace . '\\' . $declaration->name?->toString();

            if ($kind !== $declaredKind || $declaredName === null || strcasecmp($guardedName, $declaredName) !== 0) {
                continue;
            }

            $conditional[] = $namespace === ''
                ? $declaration
                : new Stmt\Namespace_(new Node\Name($namespace), [$declaration], $statement->getAttributes());
        }
    }

    private function includePath(Expr $expression, string $includingPath): ?string
    {
        if ($expression instanceof Scalar\String_) {
            return Path::resolveAbsolute($expression->value, dirname($includingPath));
        }

        if (!$expression instanceof Expr\BinaryOp\Concat) {
            return null;
        }

        $left = $expression->left;
        $right = $expression->right;

        if (!$left instanceof Scalar\MagicConst\Dir || !$right instanceof Scalar\String_) {
            return null;
        }

        return Path::resolveAbsolute(ltrim($right->value, '/\\'), dirname($includingPath));
    }

    /** @return array{'function'|'class'|'interface'|'trait'|'enum', string}|null */
    private function negativeExistenceGuard(Stmt\If_ $statement, ParsedFile $file): ?array
    {
        if ($statement->elseifs !== [] || $statement->else !== null || !$statement->cond instanceof Expr\BooleanNot) {
            return null;
        }

        $call = $statement->cond->expr;

        if (!$call instanceof Expr\FuncCall || !$call->name instanceof Node\Name || count($call->args) !== 1) {
            return null;
        }

        $kind = match (strtolower($call->name->toString())) {
            'function_exists' => 'function',
            'class_exists' => 'class',
            'interface_exists' => 'interface',
            'trait_exists' => 'trait',
            'enum_exists' => 'enum',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $callArgument = $call->args[0];

        if (!$callArgument instanceof Node\Arg) {
            return null;
        }

        $argument = $callArgument->value;
        $name = $kind === 'function'
            ? ($argument instanceof Scalar\String_ ? $argument->value : null)
            : $this->className($argument, $file);

        return is_string($name) && $name !== '' ? [$kind, ltrim($name, '\\')] : null;
    }

    private function className(?Expr $expression, ParsedFile $file): ?string
    {
        if ($expression instanceof Scalar\String_) {
            return ltrim($expression->value, '\\');
        }

        if (!$expression instanceof Expr\ClassConstFetch
            || !$expression->class instanceof Node\Name
            || !$expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'class') {
            return null;
        }

        return ltrim($this->names->resolve(
            $file,
            $expression->class->toString(),
            max(0, $expression->class->getStartFilePos()),
        ), '\\');
    }
}
