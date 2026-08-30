<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Source\Span;

final readonly class EditorSemanticToken
{
    /** @param list<string> $modifiers */
    public function __construct(
        public string $type,
        public Span $range,
        public array $modifiers = [],
    ) {}
}
