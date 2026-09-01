<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Php\Intrinsic;

final class CoreTypeRepository
{
    /** @var array<string, true> */
    private const array TYPES = [
        'throwable' => true,
        'exception' => true,
        'error' => true,
        'traversable' => true,
        'iterator' => true,
        'iteratoraggregate' => true,
        'countable' => true,
        'stringable' => true,
        'closure' => true,
        'generator' => true,
    ];

    public function contains(string $name): bool
    {
        return isset(self::TYPES[strtolower(ltrim($name, '\\'))]);
    }
}
