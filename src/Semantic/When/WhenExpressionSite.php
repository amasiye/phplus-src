<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\When;

enum WhenExpressionSite: string
{
    case TypedLocalInitializer = 'typed-local-initializer';
    case Assignment = 'assignment';
    case ReturnOperand = 'return-operand';
    case CallArgument = 'call-argument';
    case ArrayValue = 'array-value';
    case Unsupported = 'unsupported';
}
