<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

enum CallBindingIssueKind: string
{
    case ArgumentCount = 'argument-count';
    case UnknownNamedArgument = 'unknown-named-argument';
    case DuplicateNamedArgument = 'duplicate-named-argument';
    case PositionalAfterNamed = 'positional-after-named';
}
