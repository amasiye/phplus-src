<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Amasiye\Ppphp\Analysis\Enumerations\AnalysisCompleteness;
use Amasiye\Ppphp\Diagnostics\DiagnosticProcessor;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Project\SourceSet;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;

final readonly class CompilerProjectAnalyzer
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private DeclarationContextCollector $declarationCollector = new DeclarationContextCollector(),
        private DiagnosticProcessor $diagnosticProcessor = new DiagnosticProcessor(),
        private AnalysisCapabilityCatalog $capabilityCatalog = new AnalysisCapabilityCatalog(),
    ) {}

    public function analyze(Project $project, SourceSet $selectedSources): CompilerProjectAnalysis
    {
        $parseResult = $this->syntaxChecker->check($project, $selectedSources);

        if (!$parseResult->isSuccessful) {
            return new CompilerProjectAnalysis(
                $project,
                $selectedSources,
                $parseResult,
                $this->emptyContext(),
                null,
                $this->diagnosticProcessor->process($parseResult->diagnostics),
                AnalysisCompleteness::CompilerCore,
                $this->capabilityCatalog->uncoveredRequiredCapabilityIds(),
            );
        }

        $declarationContext = $this->declarationCollector->collect($project, $selectedSources);
        $semanticResult = $this->semanticAnalyzer->analyze($parseResult, $declarationContext);

        return new CompilerProjectAnalysis(
            $project,
            $selectedSources,
            $parseResult,
            $declarationContext,
            $semanticResult,
            $this->diagnosticProcessor->process($semanticResult->diagnostics),
            AnalysisCompleteness::CompilerCore,
            $this->capabilityCatalog->uncoveredRequiredCapabilityIds(),
        );
    }

    private function emptyContext(): ProjectParseResult
    {
        return new ProjectParseResult([], [], new DiagnosticBag());
    }
}
