<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Symbol;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Source\Span;

final readonly class ClassConstantSymbol
{
    public function __construct(
        public string $name,
        public ?Type $type,
        public string $visibility,
        public bool $final,
        public bool $enumCase,
        public string $declaringClass,
        public Span $declarationSpan,
        public Span $selectionSpan,
    ) {}
}
