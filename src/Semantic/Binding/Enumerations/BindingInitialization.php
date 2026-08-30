<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Binding\Enumerations;

enum BindingInitialization
{
    case Initialized;
    case MaybeUninitialized;
}
