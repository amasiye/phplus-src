<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class TypeSubstitution
{
    /** @param array<string, Type> $argumentsByParameter */
    public function __construct(private readonly array $argumentsByParameter) {}

    public function substitute(Type $type): Type
    {
        if ($type instanceof TypeParameter) {
            return $this->argumentsByParameter[$type->canonical] ?? $type;
        }

        if ($type instanceof AtomicType) {
            return $type;
        }

        if ($type instanceof GenericType) {
            return new GenericType($type->base, array_map($this->substitute(...), $type->arguments));
        }

        if ($type instanceof TypedArrayType) {
            return new TypedArrayType(
                $this->substitute($type->keyType),
                $this->substitute($type->valueType),
                $type->isList,
            );
        }

        if ($type instanceof UnionType) {
            return new UnionType(array_map($this->substitute(...), $type->members));
        }

        if ($type instanceof IntersectionType) {
            return new IntersectionType(array_map($this->substitute(...), $type->members));
        }

        return $type;
    }
}
