<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Parity;

final class Pathname
{
    public static function isUnsafe(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || in_array('..', preg_split('~[\\\\/]~', $path) ?: [], true);
    }
}
