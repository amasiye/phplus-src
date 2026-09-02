<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Versioning\Enumerations;

enum ReleaseChannel: string
{
    case Stable = 'stable';
    case ReleaseCandidate = 'rc';
    case Development = 'dev';
}
