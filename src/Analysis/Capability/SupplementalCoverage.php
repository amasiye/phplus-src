<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Capability;

enum SupplementalCoverage: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case None = 'none';
}
