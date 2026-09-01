<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

enum CallableResolutionStatus: string
{
    case Found = 'found';
    case Dynamic = 'dynamic';
    case DeferredExternal = 'deferred-external';
    case Missing = 'missing';
    case Ambiguous = 'ambiguous';
    case Invalid = 'invalid';
}
