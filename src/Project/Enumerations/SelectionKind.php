<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project\Enumerations;

enum SelectionKind: string
{
    case Project = 'project';
    case Directory = 'directory';
    case File = 'file';
}
