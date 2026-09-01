<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics\Enumerations;

enum DiagnosticStatus: string
{
    case Active = 'active';
    case Reserved = 'reserved';
}
