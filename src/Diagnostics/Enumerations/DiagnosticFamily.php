<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics\Enumerations;

enum DiagnosticFamily: string
{
    case Project = 'project';
    case Syntax = 'syntax';
    case Type = 'type';
    case Generic = 'generic';
    case CheckedError = 'checked-error';
    case When = 'when';
    case Interop = 'interop';
    case Emission = 'emission';
    case Internal = 'internal';
}
