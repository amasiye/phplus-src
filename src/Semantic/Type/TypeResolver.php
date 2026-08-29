<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use PhpParser\Node;

final class TypeResolver
{
    /** @param null|callable(Node\Name): string $resolveName */
    public function resolve(?Node $type, ?callable $resolveName = null): ?NamedType
    {
        if ($type === null) {
            return null;
        }

        return new NamedType($this->render($type, $resolveName));
    }

    /** @param null|callable(Node\Name): string $resolveName */
    private function render(Node $type, ?callable $resolveName): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        if ($type instanceof Node\Name) {
            return $resolveName === null ? $type->toString() : $resolveName($type);
        }

        if ($type instanceof Node\NullableType) {
            return '?' . $this->render($type->type, $resolveName);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn (Node $member): string => $this->render($member, $resolveName), $type->types));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn (Node $member): string => $this->render($member, $resolveName), $type->types));
        }

        throw new \LogicException(sprintf('Unsupported PHP type node "%s".', $type::class));
    }
}
