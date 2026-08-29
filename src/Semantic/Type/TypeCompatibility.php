<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

final class TypeCompatibility
{
    public function accepts(LocalType $declared, LocalType $actual): bool
    {
        if ($declared->unknown || $actual->unknown) {
            return true;
        }

        foreach ($actual->variants as $actualVariant) {
            $accepted = false;

            foreach ($declared->variants as $declaredVariant) {
                if ($this->acceptsVariant($declaredVariant, $actualVariant)) {
                    $accepted = true;
                    break;
                }
            }

            if (!$accepted) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $declared
     * @param list<string> $actual
     */
    private function acceptsVariant(array $declared, array $actual): bool
    {
        if ($declared === ['mixed'] || $actual === ['never']) {
            return true;
        }

        if (count($declared) > 1 || count($actual) > 1) {
            return true;
        }

        if ($declared === $actual) {
            return true;
        }

        if (count($declared) !== 1 || count($actual) !== 1) {
            return false;
        }

        [$expected] = $declared;
        [$received] = $actual;

        if ($expected === 'bool' && in_array($received, ['true', 'false'], true)) {
            return true;
        }

        if ($expected === 'iterable' && $received === 'array') {
            return true;
        }

        if ($expected === 'object' && !in_array($received, [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'resource', 'string', 'true', 'void',
        ], true)) {
            return true;
        }

        if ($this->resolvesNamedType($expected) && $this->resolvesNamedType($received)) {
            return true;
        }

        return false;
    }

    private function resolvesNamedType(string $name): bool
    {
        return !in_array($name, [
            'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable',
            'mixed', 'never', 'null', 'object', 'resource', 'string', 'true', 'void',
        ], true);
    }
}
