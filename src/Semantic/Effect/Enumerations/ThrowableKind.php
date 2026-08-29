<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect\Enumerations;

enum ThrowableKind
{
    case Checked;
    case Unchecked;
    case NotThrowable;
    case Unknown;
}
