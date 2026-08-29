<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;

final class ProjectCheckResult
{
    public function __construct(
        public readonly ProjectParseResult $parseResult,
        public readonly ?SemanticAnalysisResult $semanticResult,
        public readonly ?AnalysisResult $backendResult,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => $this->semanticResult !== null
            && $this->backendResult !== null
            && !$this->diagnostics->hasErrors;
    }
}
