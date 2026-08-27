<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Cli\Enumerations;

enum OutputFormat: string
{
    case Console = 'console';
    case Json = 'json';
}
