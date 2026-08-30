<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Source\Enumerations\FileKind;

final class SymbolTable
{
    /** @var array<string, ClassSymbol> */
    private array $classesByName = [];

    /** @var array<string, FunctionSymbol> */
    private array $functionsByName = [];

    /** @var array<string, ClassSymbol> */
    private array $projectClassesByName = [];

    /** @var array<string, FunctionSymbol> */
    private array $projectFunctionsByName = [];

    public function declareClass(ClassSymbol $symbol): void
    {
        $key = strtolower(ltrim($symbol->fullyQualifiedName, '\\'));
        $existing = $this->classesByName[$key] ?? null;

        if ($symbol->sourceFile->kind !== FileKind::Stub && !isset($this->projectClassesByName[$key])) {
            $this->projectClassesByName[$key] = $symbol;
        }

        if ($existing === null || ($symbol->sourceFile->kind === FileKind::Stub && $existing->sourceFile->kind !== FileKind::Stub)) {
            $this->classesByName[$key] = $symbol;
        }
    }

    public function declareFunction(FunctionSymbol $symbol): void
    {
        $key = strtolower(ltrim($symbol->fullyQualifiedName, '\\'));
        $existing = $this->functionsByName[$key] ?? null;

        if ($symbol->sourceFile->kind !== FileKind::Stub && !isset($this->projectFunctionsByName[$key])) {
            $this->projectFunctionsByName[$key] = $symbol;
        }

        if ($existing === null || ($symbol->sourceFile->kind === FileKind::Stub && $existing->sourceFile->kind !== FileKind::Stub)) {
            $this->functionsByName[$key] = $symbol;
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

    public function findProjectClass(string $fullyQualifiedName): ?ClassSymbol
    {
        return $this->projectClassesByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findProjectFunction(string $fullyQualifiedName): ?FunctionSymbol
    {
        return $this->projectFunctionsByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
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
}
