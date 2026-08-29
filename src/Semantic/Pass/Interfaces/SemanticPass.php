<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Pass\Interfaces;

use Amasiye\Ppphp\Semantic\SemanticContext;

interface SemanticPass
{
    public function execute(SemanticContext $context): void;
}
