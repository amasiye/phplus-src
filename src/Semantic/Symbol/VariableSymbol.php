<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Binding\Enumerations\BindingMutability;
use Amasiye\Ppphp\Semantic\Binding\LocalBinding;
use Amasiye\Ppphp\Semantic\Type\LocalType;
use Amasiye\Ppphp\Source\Span;

final readonly class VariableSymbol
{
    public function __construct(
        public string $name,
        public LocalType $type,
        public BindingMutability $mutability,
        public ?Span $declarationSpan = null,
        public ?LocalBinding $binding = null,
    ) {}
}
