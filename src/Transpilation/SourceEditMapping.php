<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Source\Span;

final readonly class SourceEditMapping
{
    public function __construct(
        public int $replacementStart,
        public int $replacementEnd,
        public Span $origin,
    ) {
        if ($replacementStart < 0 || $replacementEnd < $replacementStart) {
            throw new \InvalidArgumentException('A source-edit mapping must describe a valid replacement range.');
        }
    }
}
