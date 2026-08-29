<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Token;

/** @implements \IteratorAggregate<int, Token> */
final readonly class TokenStream implements \Countable, \IteratorAggregate
{
    /** @param list<Token> $tokens */
    public function __construct(public array $tokens) {}

    public function count(): int
    {
        return count($this->tokens);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->tokens;
    }

    /** @return list<Token> */
    public function resolveSignificantTokens(): array
    {
        return array_values(array_filter(
            $this->tokens,
            static fn (Token $token): bool => !$token->isTrivia,
        ));
    }
}
