<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class UnknownType implements Type
{
    public string $canonical {
        get => 'unknown';
    }

    public bool $isNullable {
        get => true;
    }

    public bool $isUnknown {
        get => true;
    }

    public function renderPhpDoc(): string
    {
        return 'mixed';
    }

    public function eraseToNative(): string
    {
        return 'mixed';
    }
}
