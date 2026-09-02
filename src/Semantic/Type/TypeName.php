<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

final readonly class TypeName
{
    public static function resolveShort(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
