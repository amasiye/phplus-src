<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Capability;

enum CompilerCoverage: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case BackendOnly = 'backend-only';
}
