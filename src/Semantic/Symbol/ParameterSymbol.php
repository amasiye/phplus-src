<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\Span;

final readonly class ParameterSymbol
{
    public function __construct(
        public string $name,
        public ?NamedType $type,
        public bool $variadic,
        public bool $byReference,
        public bool $promoted,
        public Span $declarationSpan,
        public Span $selectionSpan,
        public ?Type $documentedType = null,
        public int $position = 0,
        public bool $hasDefault = false,
        public ?Type $defaultType = null,
    ) {}

    public function effectiveType(): ?Type
    {
        $native = $this->type?->semanticType;

        if ($this->documentedType !== null
            && ($this->declarationSpan->sourceFile->kind !== FileKind::Ppphp || $native === null || $native->canonical === 'mixed')) {
            return $this->documentedType;
        }

        return $native;
    }
}
