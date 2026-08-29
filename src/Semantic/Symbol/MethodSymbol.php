<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Source\Span;

final readonly class MethodSymbol
{
    /** @param list<ParameterSymbol> $parameters */
    public function __construct(
        public string $owner,
        public string $name,
        public array $parameters,
        public ?NamedType $returnType,
        public string $visibility,
        public bool $static,
        public bool $byReference,
        public Span $declarationSpan,
    ) {}
}
