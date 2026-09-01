<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Project\SourceSet;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;

final class CompilerProjectAnalysis
{
    /** @param list<string> $uncoveredRequiredCapabilities */
    public function __construct(
        public readonly Project $project,
        public readonly SourceSet $selectedSources,
        public readonly ProjectParseResult $parseResult,
        public readonly ProjectParseResult $declarationContext,
        public readonly ?SemanticAnalysisResult $semanticResult,
        public readonly DiagnosticBag $diagnostics,
        public readonly AnalysisCompleteness $completeness = AnalysisCompleteness::CompilerCore,
        public readonly array $uncoveredRequiredCapabilities = [],
    ) {}

    public bool $isSuccessful {
        get => $this->semanticResult !== null && !$this->diagnostics->hasErrors;
    }
}
