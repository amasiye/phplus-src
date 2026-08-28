<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source\Enumerations;

enum FileKind: string
{
    case Php = 'php';
    case Ppp = 'ppp';
    case Stub = 'stub';
    case Configuration = 'configuration';

    public static function resolveFromPath(string $path): self
    {
        if (str_ends_with(strtolower($path), '.stub.php')) {
            return self::Stub;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => self::Php,
            'ppp' => self::Ppp,
            default => self::Stub,
        };
    }
}
