<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

enum CallableKind: string
{
    case Function = 'function';
    case Method = 'method';
    case Constructor = 'constructor';
    case Intrinsic = 'intrinsic';
}
