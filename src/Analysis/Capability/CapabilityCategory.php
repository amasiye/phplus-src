<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Capability;

enum CapabilityCategory: string
{
    case Syntax = 'syntax';
    case Declarations = 'declarations';
    case TypeSystem = 'type-system';
    case Flow = 'flow';
    case CallsAndMembers = 'calls-and-members';
    case Generics = 'generics';
    case Collections = 'collections';
    case CheckedErrors = 'checked-errors';
    case Interoperability = 'interoperability';
    case Infrastructure = 'infrastructure';
}
