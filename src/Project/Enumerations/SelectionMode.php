<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project\Enumerations;

enum SelectionMode
{
    case Check;
    case Build;
    case DumpAst;
}
