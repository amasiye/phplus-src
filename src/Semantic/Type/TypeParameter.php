<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Span;

final class TypeParameter implements Type
{
    public function __construct(
        public readonly string $name,
        public readonly ?Type $bound,
        public readonly string $ownerKey,
        public readonly Span $span,
    ) {}

    public string $canonical {
        get => '$' . $this->ownerKey . ':' . $this->name;
    }

    public bool $isNullable {
        get => $this->bound === null || $this->bound->isNullable;
    }

    public bool $isUnknown {
        get => false;
    }

    public function renderPhpDoc(): string
    {
        return $this->name;
    }

    public function eraseToNative(): string
    {
        return $this->bound?->eraseToNative() ?? 'mixed';
    }
}
