<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingInitialization;
use Amasiye\Ppphp\Semantic\Binding\LocalBinding;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Source\Span;

final class VariableSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly LocalType $type,
        public readonly BindingMutability $mutability,
        public readonly ?Span $declarationSpan = null,
        public readonly ?LocalBinding $binding = null,
    ) {}

    public BindingInitialization $initialization {
        get => $this->binding === null
            ? BindingInitialization::Initialized
            : $this->binding->initialization;
    }
}
