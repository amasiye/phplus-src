<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
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
        public Span $selectionSpan,
        public ?Type $documentedType = null,
        public ?string $writeVisibility = null,
        public bool $promoted = false,
        public bool $hasDefault = false,
        public ?Type $defaultType = null,
        public bool $hasBackingStorage = true,
        public bool $hasGetter = false,
        public bool $hasSetter = false,
        public bool $abstract = false,
        public bool $virtual = false,
        public string $declaringClass = '',
    ) {}

    public function effectiveType(): ?Type
    {
        $native = $this->type?->semanticType;

        if ($this->documentedType !== null && ($native === null || $native->canonical === 'mixed')) {
            return $this->documentedType;
        }

        return $native;
    }

    public function effectiveWriteVisibility(): string
    {
        return $this->writeVisibility ?? $this->visibility;
    }
}
