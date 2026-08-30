<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class TypedArrayType implements Type
{
    public function __construct(
        public readonly Type $keyType,
        public readonly Type $valueType,
        public readonly bool $isList,
    ) {}

    public string $canonical {
        get => $this->isList
            ? 'array<' . $this->valueType->canonical . '>'
            : 'array<' . $this->keyType->canonical . ',' . $this->valueType->canonical . '>';
    }

    public bool $isNullable {
        get => false;
    }

    public bool $isUnknown {
        get => $this->keyType->isUnknown || $this->valueType->isUnknown;
    }

    public function renderPhpDoc(): string
    {
        return $this->isList
            ? 'list<' . $this->valueType->renderPhpDoc() . '>'
            : 'array<' . $this->keyType->renderPhpDoc() . ', ' . $this->valueType->renderPhpDoc() . '>';
    }

    public function eraseToNative(): string
    {
        return 'array';
    }
}
