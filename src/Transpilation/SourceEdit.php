<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Source\Span;

final readonly class SourceEdit
{
    public function __construct(
        public Span $span,
        public string $replacement,
    ) {}
}
