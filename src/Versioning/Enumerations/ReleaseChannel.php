<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning\Enumerations;

enum ReleaseChannel: string
{
    case Stable = 'stable';
    case ReleaseCandidate = 'rc';
    case Development = 'dev';
}
