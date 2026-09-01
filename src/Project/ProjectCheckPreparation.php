<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;

final class ProjectCheckPreparation
{
    public function __construct(
        public readonly ProjectParseResult $parseResult,
        public readonly ?SemanticAnalysisResult $semanticResult,
        public readonly ?AnalysisProject $analysisProject,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => $this->semanticResult !== null
            && $this->analysisProject !== null
            && !$this->diagnostics->hasErrors;
    }
}
