<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Ast\Enumerations;

enum ForeachBindingPosition: string
{
    case Key = 'key';
    case Value = 'value';
}
