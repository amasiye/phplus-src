<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

enum ExpressionResolutionStatus: string
{
    case Known = 'known';
    case Dynamic = 'dynamic';
    case DeferredExternal = 'deferred-external';
    case UnknownExpression = 'unknown-expression';
    case Missing = 'missing';
    case Invalid = 'invalid';
}
