<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Validation\Interfaces;

use Atatusoft\Ppphp\Compiler\Validation\PhpLintResult;

interface PhpLintRunner
{
    public function run(string $path, float $timeoutSeconds): PhpLintResult;
}
