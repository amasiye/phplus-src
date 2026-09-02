<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Project\SourceSet;
use Atatusoft\Ppphp\Semantic\SemanticAnalysisResult;

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
