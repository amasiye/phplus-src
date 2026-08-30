<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class TypeCompatibility
{
    public function accepts(LocalType $declared, LocalType $actual, ?SymbolTable $symbols = null): bool
    {
        return $this->acceptsType($declared->semanticType, $actual->semanticType, $symbols);
    }

    private function acceptsType(Type $declared, Type $actual, ?SymbolTable $symbols): bool
    {
        if ($declared->isUnknown || $actual->isUnknown) {
            return true;
        }

        if ($actual instanceof UnionType) {
            foreach ($actual->members as $member) {
                if (!$this->acceptsType($declared, $member, $symbols)) {
                    return false;
                }
            }

            return true;
        }

        if ($declared instanceof UnionType) {
            foreach ($declared->members as $member) {
                if ($this->acceptsType($member, $actual, $symbols)) {
                    return true;
                }
            }

            return false;
        }

        if ($declared instanceof IntersectionType) {
            foreach ($declared->members as $member) {
                if (!$this->acceptsType($member, $actual, $symbols)) {
                    return false;
                }
            }

            return true;
        }

        if ($actual instanceof IntersectionType) {
            foreach ($actual->members as $member) {
                if ($this->acceptsType($declared, $member, $symbols)) {
                    return true;
                }
            }

            return false;
        }

        if ($declared instanceof GenericType && $actual instanceof GenericType) {
            return $declared->canonical === $actual->canonical;
        }

        if ($declared instanceof GenericType && $actual instanceof AtomicType) {
            if ($declared->base->canonical === $actual->canonical) {
                return true;
            }

            return $this->satisfiesHierarchy($actual->name, $declared->base->name, $symbols) ?? true;
        }

        if ($declared instanceof TypedArrayType && $actual instanceof TypedArrayType) {
            return $declared->canonical === $actual->canonical;
        }

        if ($declared instanceof TypedArrayType && $actual instanceof AtomicType) {
            return $actual->canonical === 'array';
        }

        if ($declared instanceof AtomicType && $actual instanceof TypedArrayType) {
            return $declared->canonical === 'array';
        }

        if (!$declared instanceof AtomicType || !$actual instanceof AtomicType) {
            return $declared->canonical === $actual->canonical;
        }

        if ($declared->canonical === 'mixed' || $actual->canonical === 'never') {
            return true;
        }

        if ($declared->canonical === $actual->canonical) {
            return true;
        }

        if ($declared->canonical === 'bool' && in_array($actual->canonical, ['true', 'false'], true)) {
            return true;
        }

        if ($declared->canonical === 'iterable' && $actual->canonical === 'array') {
            return true;
        }

        if ($declared->canonical === 'object' && !$actual->isBuiltin) {
            return true;
        }

        if ($declared->isBuiltin || $actual->isBuiltin) {
            return false;
        }

        $hierarchyResult = $this->satisfiesHierarchy($actual->name, $declared->name, $symbols);

        return $hierarchyResult ?? true;
    }

    private function satisfiesHierarchy(string $actual, string $declared, ?SymbolTable $symbols): ?bool
    {
        if ($symbols === null) {
            return null;
        }

        $actualSymbol = $this->findClass($actual, $symbols);
        $declaredSymbol = $this->findClass($declared, $symbols);

        if ($actualSymbol === null || $declaredSymbol === null) {
            return null;
        }

        $visited = [];

        return $this->classSatisfies($actualSymbol, $declaredSymbol->fullyQualifiedName, $symbols, $visited);
    }

    private function findClass(string $name, SymbolTable $symbols): ?ClassSymbol
    {
        $exact = $symbols->findClass($name);

        if ($exact !== null) {
            return $exact;
        }

        $matches = array_values(array_filter(
            $symbols->classes,
            static fn (ClassSymbol $symbol): bool => strcasecmp(
                TypeName::resolveShort($symbol->fullyQualifiedName),
                ltrim($name, '\\'),
            ) === 0,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string, true> $visited */
    private function classSatisfies(
        ClassSymbol $actual,
        string $declared,
        SymbolTable $symbols,
        array &$visited,
    ): bool {
        $key = strtolower(ltrim($actual->fullyQualifiedName, '\\'));

        if (isset($visited[$key])) {
            return false;
        }

        $visited[$key] = true;

        if (strcasecmp($actual->fullyQualifiedName, $declared) === 0) {
            return true;
        }

        foreach ([...$actual->interfaces, ...$actual->traits, ...($actual->parent === null ? [] : [$actual->parent])] as $relatedName) {
            if (strcasecmp($relatedName, $declared) === 0) {
                return true;
            }

            $related = $symbols->findClass($relatedName);

            if ($related !== null && $this->classSatisfies($related, $declared, $symbols, $visited)) {
                return true;
            }
        }

        return false;
    }
}
