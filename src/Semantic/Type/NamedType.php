<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class NamedType implements Type
{
    public readonly string $text;

    public readonly Type $semanticType;

    public function __construct(
        string|Type $type,
        public readonly bool $explicit = true,
    ) {
        $this->semanticType = is_string($type)
            ? (new CompositeTypeParser())->parse($type)
            : $type;
        $this->text = is_string($type) ? $type : $type->renderPhpDoc();
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
