<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

enum CallableKind: string
{
    case Function = 'function';
    case Method = 'method';
    case Constructor = 'constructor';
    case Intrinsic = 'intrinsic';
}
