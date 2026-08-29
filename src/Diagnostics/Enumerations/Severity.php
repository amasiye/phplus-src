<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics\Enumerations;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Note = 'note';
}
