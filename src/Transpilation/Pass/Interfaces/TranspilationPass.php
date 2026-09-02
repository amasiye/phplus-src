<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation\Pass\Interfaces;

use Atatusoft\Ppphp\Transpilation\TranspilationContext;

interface TranspilationPass
{
    public function execute(TranspilationContext $context): void;
}
