<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

use Atatusoft\Ppphp\Source\Span;

final readonly class EditorSemanticToken
{
    /** @param list<string> $modifiers */
    public function __construct(
        public string $type,
        public Span $range,
        public array $modifiers = [],
    ) {}
}
