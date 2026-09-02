<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

enum MemberResolutionStatus: string
{
    case Found = 'found';
    case Missing = 'missing';
    case Ambiguous = 'ambiguous';
    case DeferredExternal = 'deferred-external';
    case UnknownReceiver = 'unknown-receiver';
    case InvalidStaticAccess = 'invalid-static-access';
    case InvalidInstanceAccess = 'invalid-instance-access';
    case VisibilityFailure = 'visibility-failure';
}
