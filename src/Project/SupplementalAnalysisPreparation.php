<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Analysis\CompilerProjectAnalysis;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

final class SupplementalAnalysisPreparation
{
    public function __construct(
        public readonly CompilerProjectAnalysis $compilerAnalysis,
        public readonly ?AnalysisProject $analysisProject,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => $this->compilerAnalysis->isSuccessful
            && $this->analysisProject !== null
            && !$this->diagnostics->hasErrors;
    }
}
