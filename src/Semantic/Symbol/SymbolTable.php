<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

final class SymbolTable
{
    /** @var array<string, ClassSymbol> */
    private array $classesByName = [];

    /** @var array<string, FunctionSymbol> */
    private array $functionsByName = [];

    public function declareClass(ClassSymbol $symbol): void
    {
        $this->classesByName[strtolower(ltrim($symbol->fullyQualifiedName, '\\'))] ??= $symbol;
    }

    public function declareFunction(FunctionSymbol $symbol): void
    {
        $this->functionsByName[strtolower(ltrim($symbol->fullyQualifiedName, '\\'))] ??= $symbol;
    }

    public function findClass(string $fullyQualifiedName): ?ClassSymbol
    {
        return $this->classesByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
    }

    public function findFunction(string $fullyQualifiedName): ?FunctionSymbol
    {
        return $this->functionsByName[strtolower(ltrim($fullyQualifiedName, '\\'))] ?? null;
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
