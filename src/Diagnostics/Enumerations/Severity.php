<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics\Enumerations;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Note = 'note';
}
