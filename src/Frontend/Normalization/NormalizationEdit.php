<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Normalization;

use Amasiye\Ppphp\Frontend\Ast\NodeId;
use Amasiye\Ppphp\Source\Span;

final readonly class NormalizationEdit
{
    public function __construct(
        public Span $span,
        public string $replacement,
        public NodeId $owner,
    ) {
        if (strlen($replacement) !== $span->end->offset - $span->start->offset) {
            throw new \InvalidArgumentException('Normalization edits must preserve byte length.');
        }

        if ($this->resolveNewlineBytes($replacement) !== $this->resolveNewlineBytes($span->text)) {
            throw new \InvalidArgumentException('Normalization edits must preserve newline bytes.');
        }
    }

    private function resolveNewlineBytes(string $text): string
    {
        return implode('', array_filter(
            str_split($text),
            static fn (string $byte): bool => $byte === "\r" || $byte === "\n",
        ));
    }
}
