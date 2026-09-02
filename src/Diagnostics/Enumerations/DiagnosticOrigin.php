<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics\Enumerations;

enum DiagnosticOrigin: string
{
    case Compiler = 'compiler';
    case PhpParser = 'php-parser';
    case PhpStan = 'phpstan';
    case Subprocess = 'subprocess';
}
