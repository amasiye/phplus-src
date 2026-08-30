<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Source\Span;

final readonly class EditorDefinition
{
    public function __construct(
        public string $symbolId,
        public string $kind,
        public Span $declarationSpan,
        public Span $selectionSpan,
    ) {}
}
