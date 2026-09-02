<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Enumerations;

enum CompilationFailureKind
{
    case Source;
    case Output;
}
