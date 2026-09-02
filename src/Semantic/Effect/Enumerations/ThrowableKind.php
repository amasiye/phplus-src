<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Effect\Enumerations;

enum ThrowableKind
{
    case Checked;
    case Unchecked;
    case NotThrowable;
    case Unknown;
}
