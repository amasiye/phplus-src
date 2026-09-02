<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cli\Enumerations;

enum OutputFormat: string
{
    case Console = 'console';
    case Json = 'json';
}
