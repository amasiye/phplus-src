<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source\Enumerations;

enum FileKind: string
{
    case Php = 'php';
    case Phplus = 'phplus';
    case Stub = 'stub';
    case Configuration = 'configuration';

    public static function resolveFromPath(string $path): self
    {
        if (str_ends_with(strtolower($path), '.stub.php')) {
            return self::Stub;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => self::Php,
            'phplus' => self::Phplus,
            default => self::Stub,
        };
    }
}
