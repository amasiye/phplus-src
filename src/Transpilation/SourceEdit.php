<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

use Atatusoft\Ppphp\Source\Span;

final readonly class SourceEdit
{
    /** @param list<SourceEditMapping> $mappings */
    public function __construct(
        public Span $span,
        public string $replacement,
        public array $mappings = [],
    ) {}
}
