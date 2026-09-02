<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalysis;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

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
