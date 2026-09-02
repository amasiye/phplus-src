<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Declaration;

enum DeclarationOrigin: string
{
    case ProjectPpphp = 'project-ppphp';
    case ProjectPhp = 'project-php';
    case ConfiguredStub = 'configured-stub';
    case ComposerDependency = 'composer-dependency';
    case ConditionalComposerDependency = 'conditional-composer-dependency';
    case PhpPlatform = 'php-platform';
    case IntrinsicOverride = 'intrinsic-override';
}
