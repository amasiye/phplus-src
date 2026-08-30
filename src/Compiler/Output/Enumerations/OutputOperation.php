<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output\Enumerations;

enum OutputOperation: string
{
    case Compile = 'compile';
    case Copy = 'copy';
}
