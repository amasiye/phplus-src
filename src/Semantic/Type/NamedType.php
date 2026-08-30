<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class NamedType implements Type
{
    private readonly Type $semanticType;

    public function __construct(
        public readonly string $text,
        public readonly bool $explicit = true,
    ) {
        $this->semanticType = (new CompositeTypeParser())->parse($text);
    }

    public bool $allowsNull {
        get => $this->semanticType->isNullable;
    }

    public string $canonical {
        get => $this->semanticType->canonical;
    }

    public bool $isNullable {
        get => $this->semanticType->isNullable;
    }

    public bool $isUnknown {
        get => $this->semanticType->isUnknown;
    }

    public function renderPhpDoc(): string
    {
        return $this->semanticType->renderPhpDoc();
    }

    public function eraseToNative(): string
    {
        return $this->semanticType->eraseToNative();
    }

    public function resolveSingleNamedType(): ?string
    {
        $atomic = $this->semanticType instanceof GenericType
            ? $this->semanticType->base
            : $this->semanticType;

        return $atomic instanceof AtomicType && !$atomic->isBuiltin
            ? $atomic->name
            : null;
    }
}
