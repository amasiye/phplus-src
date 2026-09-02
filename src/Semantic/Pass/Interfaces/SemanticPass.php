<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Pass\Interfaces;

use Atatusoft\Ppphp\Semantic\SemanticContext;

interface SemanticPass
{
    public function execute(SemanticContext $context): void;
}
