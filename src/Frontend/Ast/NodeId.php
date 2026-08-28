<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Ast;

use Amasiye\Phplus\Source\Span;

final readonly class NodeId implements \Stringable
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('An extension node identity cannot be empty.');
        }
    }

    public static function create(string $kind, Span $span): self
    {
        return new self(sprintf('%s@%d:%d', $kind, $span->start->offset, $span->end->offset));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
