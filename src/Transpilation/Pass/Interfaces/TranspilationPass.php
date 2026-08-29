<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation\Pass\Interfaces;

use Amasiye\Ppphp\Transpilation\TranspilationContext;

interface TranspilationPass
{
    public function execute(TranspilationContext $context): void;
}
