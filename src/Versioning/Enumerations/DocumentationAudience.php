<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning\Enumerations;

enum DocumentationAudience: string
{
    case Public = 'public';
    case Maintainer = 'maintainer';
    case Technical = 'technical';
}
