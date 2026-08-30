<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Enumerations;

enum CompilationFailureKind
{
    case Source;
    case Output;
}
