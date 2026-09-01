<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

enum CallableOrigin: string
{
    case Ppphp = 'ppphp';
    case Php = 'php';
    case Stub = 'stub';
    case Intrinsic = 'intrinsic';
}
