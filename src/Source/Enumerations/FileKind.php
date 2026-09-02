<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Source\Enumerations;

enum FileKind: string
{
    public const string PHP_SUFFIX = '.php';
    public const string PPPHP_SUFFIX = '.ppphp';

    case Php = 'php';
    case Ppphp = 'ppphp';
    case Stub = 'stub';
    case Configuration = 'configuration';

    public static function resolveFromPath(string $path): self
    {
        if (str_ends_with(strtolower($path), '.stub.php')) {
            return self::Stub;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            self::Php->value => self::Php,
            self::Ppphp->value => self::Ppphp,
            default => self::Stub,
        };
    }
}
