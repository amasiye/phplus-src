<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Validation\Interfaces;

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

interface PhpValidator
{
    public function validate(CompilationArtifact $artifact, string $candidatePath): DiagnosticBag;
}
