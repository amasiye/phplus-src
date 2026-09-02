<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

enum TypeCompatibilityResult
{
    case Compatible;
    case Incompatible;
    case Unknown;

    public function isAccepted(): bool
    {
        return $this !== self::Incompatible;
    }
}
