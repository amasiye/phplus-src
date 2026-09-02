<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics\Enumerations;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Note = 'note';
}
