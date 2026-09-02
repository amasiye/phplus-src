<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Normalization;

use Atatusoft\Ppphp\Frontend\Ast\NodeId;
use Atatusoft\Ppphp\Source\Span;

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
