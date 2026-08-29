<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cli\Enumerations;

enum ExitCode: int
{
    case Success = 0;
    case DiagnosticsReported = 1;
    case InvalidProject = 2;
    case OutputValidationFailed = 3;
    case InternalCompilerFailure = 70;
}
