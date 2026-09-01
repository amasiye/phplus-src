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

        if ($declared instanceof TypeParameter) {
            if ($actual instanceof TypeParameter && $declared->canonical === $actual->canonical) {
                return true;
            }

            return $declared->bound === null || $this->acceptsType($declared->bound, $actual, $symbols);
        }

        if ($actual instanceof TypeParameter) {
            return $actual->bound === null || $this->acceptsType($declared, $actual->bound, $symbols);
        }

        if ($declared instanceof GenericType && ($actual instanceof GenericType || $actual instanceof AtomicType)) {
            if ($actual instanceof AtomicType && $declared->base->canonical === $actual->canonical) {
                return true;
            }

            if ($symbols === null) {
                return $declared->canonical === $actual->canonical;
            }

            return $this->satisfiesAppliedHierarchy($actual, $declared, $symbols);
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

        if ($declared instanceof AtomicType && $actual instanceof GenericType) {
            if ($declared->canonical === $actual->base->canonical) {
                return true;
            }

            return $this->satisfiesHierarchy($actual->base->name, $declared->name, $symbols) ?? true;
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

    /** @param array<string, true> $visited */
    private function satisfiesAppliedHierarchy(
        AtomicType|GenericType $actual,
        GenericType $declared,
        SymbolTable $symbols,
        array &$visited = [],
    ): bool {
        if ($actual instanceof GenericType && $actual->canonical === $declared->canonical) {
            return true;
        }

        $className = $actual instanceof GenericType ? $actual->base->name : $actual->name;
        $class = $this->findClass($className, $symbols);

        if ($class === null) {
            return false;
        }

        $visitKey = strtolower($class->fullyQualifiedName . '<' . $actual->canonical . '>');

        if (isset($visited[$visitKey])) {
            return false;
        }

        $visited[$visitKey] = true;
        $arguments = [];

        if ($actual instanceof GenericType && $class->genericDeclaration !== null) {
            foreach ($class->genericDeclaration->parameters as $index => $parameter) {
                if (isset($actual->arguments[$index])) {
                    $arguments[$parameter->canonical] = $actual->arguments[$index];
                }
            }
        }

        $substitution = new TypeSubstitution($arguments);
        $related = [
            ...$class->interfaceTypes,
            ...$class->traitTypes,
            ...($class->parentType === null ? [] : [$class->parentType]),
        ];

        foreach ($related as $namedType) {
            $candidate = $substitution->substitute($namedType->semanticType);

            if ($candidate->canonical === $declared->canonical) {
                return true;
            }

            if (($candidate instanceof AtomicType || $candidate instanceof GenericType)
                && $this->satisfiesAppliedHierarchy($candidate, $declared, $symbols, $visited)) {
                return true;
            }
        }

        return false;
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
