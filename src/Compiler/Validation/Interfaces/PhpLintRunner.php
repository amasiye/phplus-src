<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Validation\Interfaces;

use Amasiye\Ppphp\Compiler\Validation\PhpLintResult;

interface PhpLintRunner
{
    public function run(string $path, float $timeoutSeconds): PhpLintResult;
}
