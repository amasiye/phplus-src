<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

/**
 * @phpstan-type MemberSymbol array{availability: string|null, kind: string, name: string}
 * @phpstan-type StubSymbol array{availability: string|null, kind: string, name: string, members?: list<MemberSymbol>}
 */
final readonly class PhpStubNormalization
{
    /**
     * @param array{functions: int, classLikes: int, methods: int, properties: int, constants: int, aliases: int} $counts
     * @param list<StubSymbol> $symbols
     * @param list<array{declaration: string, target: string, kind: string}> $aliases
     * @param array<string, array{count: int, disposition: string}> $directives
     */
    public function __construct(
        public string $source,
        public array $counts,
        public array $symbols,
        public array $aliases,
        public array $directives,
    ) {}
}
