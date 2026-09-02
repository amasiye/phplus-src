<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Token;

use Atatusoft\Ppphp\Frontend\Token\Enumerations\TokenKind;
use Atatusoft\Ppphp\Source\Span;

final class Token
{
    public function __construct(
        public readonly TokenKind $kind,
        public readonly int $lexicalId,
        public readonly string $text,
        public readonly int $start,
        public readonly int $end,
        public readonly Span $span,
        public readonly bool $isTrivia,
    ) {
        if ($start < 0 || $end < $start || $end - $start !== strlen($text)) {
            throw new \InvalidArgumentException('A token must use a valid half-open byte range.');
        }
    }

    public int $line {
        get => $this->span->start->line;
    }

    public int $column {
        get => $this->span->start->column;
    }
}
