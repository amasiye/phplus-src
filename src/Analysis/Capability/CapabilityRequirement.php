<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Capability;

enum CapabilityRequirement: string
{
    case Mvp = 'mvp';
    case Boundary = 'boundary';
    case Optional = 'optional';
}
