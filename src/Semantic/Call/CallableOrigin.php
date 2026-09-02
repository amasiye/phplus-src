<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

enum CallableOrigin: string
{
    case ProjectPpphp = 'project-ppphp';
    case ProjectPhp = 'project-php';
    case ConfiguredStub = 'configured-stub';
    case ComposerDependency = 'composer-dependency';
    case PhpPlatform = 'php-platform';
    case IntrinsicOverride = 'intrinsic-override';
}
