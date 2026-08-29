<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Source\Span;

final readonly class PropertySymbol
{
    public function __construct(
        public string $name,
        public ?NamedType $type,
        public string $visibility,
        public bool $static,
        public bool $readonly,
        public Span $declarationSpan,
    ) {}
}
