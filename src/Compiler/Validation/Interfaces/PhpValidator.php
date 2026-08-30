<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Validation\Interfaces;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

interface PhpValidator
{
    public function validate(CompilationArtifact $artifact, string $candidatePath): DiagnosticBag;
}
