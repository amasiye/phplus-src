<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Analysis\Declaration\DeclarationOrigin;

final class SymbolTable
{
    /** @var array<string, ClassSymbol> */
    private array $classesByName = [];

    /** @var array<string, FunctionSymbol> */
    private array $functionsByName = [];

    /** @var array<string, GlobalConstantSymbol> */
    private array $constantsByName = [];

    /** @var array<string, ClassSymbol> */
    private array $projectClassesByName = [];

    /** @var array<string, FunctionSymbol> */
    private array $projectFunctionsByName = [];

    /** @var array<string, GlobalConstantSymbol> */
    private array $projectConstantsByName = [];

    public function declareClass(ClassSymbol $symbol): void
    {
        $key = strtolower(ltrim($symbol->fullyQualifiedName, '\\'));
        $existing = $this->classesByName[$key] ?? null;

        if ($this->isProject($symbol->sourceFile->declarationOrigin) && !isset($this->projectClassesByName[$key])) {
            $this->projectClassesByName[$key] = $symbol;
        }

        if ($existing === null || $this->precedence($symbol->sourceFile->declarationOrigin) > $this->precedence($existing->sourceFile->declarationOrigin)) {
            $this->classesByName[$key] = $symbol;
        }
    }

    public function declareFunction(FunctionSymbol $symbol): void
    {
        $key = strtolower(ltrim($symbol->fullyQualifiedName, '\\'));
        $existing = $this->functionsByName[$key] ?? null;

        if ($this->isProject($symbol->sourceFile->declarationOrigin) && !isset($this->projectFunctionsByName[$key])) {
            $this->projectFunctionsByName[$key] = $symbol;
        }

        if ($existing === null || $this->precedence($symbol->sourceFile->declarationOrigin) > $this->precedence($existing->sourceFile->declarationOrigin)) {
            $this->functionsByName[$key] = $symbol;
        }
    }

    public function declareConstant(GlobalConstantSymbol $symbol): void
    {
        $key = ltrim($symbol->fullyQualifiedName, '\\');
        $existing = $this->constantsByName[$key] ?? null;

        if ($this->isProject($symbol->sourceFile->declarationOrigin) && !isset($this->projectConstantsByName[$key])) {
            $this->projectConstantsByName[$key] = $symbol;
        }

        if ($existing === null || $this->precedence($symbol->sourceFile->declarationOrigin) > $this->precedence($existing->sourceFile->declarationOrigin)) {
            $this->constantsByName[$key] = $symbol;
        }
    }

    public function findClass(string $fullyQualifiedName): ?ClassSymbol
    {
        return $this->classesByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findFunction(string $fullyQualifiedName): ?FunctionSymbol
    {
        return $this->functionsByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findConstant(string $fullyQualifiedName): ?GlobalConstantSymbol
    {
        return $this->constantsByName[ltrim($fullyQualifiedName, '\\')] ?? null;
    }

    public function findProjectClass(string $fullyQualifiedName): ?ClassSymbol
    {
        return $this->projectClassesByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findProjectFunction(string $fullyQualifiedName): ?FunctionSymbol
    {
        return $this->projectFunctionsByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findProjectConstant(string $fullyQualifiedName): ?GlobalConstantSymbol
    {
        return $this->projectConstantsByName[ltrim($fullyQualifiedName, '\\')] ?? null;
    }

    private function isProject(DeclarationOrigin $origin): bool
    {
        return in_array($origin, [DeclarationOrigin::ProjectPpphp, DeclarationOrigin::ProjectPhp], true);
    }

    private function precedence(DeclarationOrigin $origin): int
    {
        return match ($origin) {
            DeclarationOrigin::ConfiguredStub => 50,
            DeclarationOrigin::ProjectPpphp, DeclarationOrigin::ProjectPhp => 40,
            DeclarationOrigin::ComposerDependency => 30,
            DeclarationOrigin::PhpPlatform => 20,
            DeclarationOrigin::IntrinsicOverride => 10,
        };
    }

    public function acceptsPropertyWrite(ClassSymbol $class, string $name): bool
    {
        $visited = [];

        return $this->acceptsPropertyWriteThroughHierarchy($class, $name, true, $visited);
    }

    /** @param array<string, true> $visited */
    private function acceptsPropertyWriteThroughHierarchy(
        ClassSymbol $class,
        string $name,
        bool $declaringScope,
        array &$visited,
    ): bool {
        $key = strtolower(ltrim($class->fullyQualifiedName, '\\'));

        if (isset($visited[$key])) {
            return true;
        }

        $visited[$key] = true;
        $property = $class->findProperty($name);

        if ($property !== null && ($declaringScope || $property->visibility !== 'private')) {
            return true;
        }

        foreach ($class->traits as $traitName) {
            $trait = $this->findClass($traitName);

            if ($trait === null || $this->acceptsPropertyWriteThroughHierarchy($trait, $name, $declaringScope, $visited)) {
                return true;
            }
        }

        if ($class->parent === null) {
            return false;
        }

        $parent = $this->findClass($class->parent);

        return $parent === null
            || $this->acceptsPropertyWriteThroughHierarchy($parent, $name, false, $visited);
    }

    /** @var list<ClassSymbol> */
    public array $classes {
        get => array_values($this->classesByName);
    }

    /** @var list<FunctionSymbol> */
    public array $functions {
        get => array_values($this->functionsByName);
    }

    /** @var list<GlobalConstantSymbol> */
    public array $constants {
        get => array_values($this->constantsByName);
    }
}
