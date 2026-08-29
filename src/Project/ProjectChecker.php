<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Amasiye\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\DiagnosticDeduplicator;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;

final readonly class ProjectChecker
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private AnalysisWorkspacePreparer $workspacePreparer = new AnalysisWorkspacePreparer(),
        private ProjectAnalyzer $backend = new PhpStanProjectAnalyzer(),
        private DiagnosticDeduplicator $deduplicator = new DiagnosticDeduplicator(),
    ) {}

    public function check(Project $project, SourceSet $selectedSources): ProjectCheckResult
    {
        $parseResult = $this->syntaxChecker->check($project, $selectedSources);

        if (!$parseResult->isSuccessful) {
            return new ProjectCheckResult($parseResult, null, null, $parseResult->diagnostics);
        }

        $initialSemantic = $this->semanticAnalyzer->analyze($parseResult);

        if (!$initialSemantic->isSuccessful) {
            return new ProjectCheckResult($parseResult, $initialSemantic, null, $initialSemantic->diagnostics);
        }

        $preparation = $this->workspacePreparer->prepare(
            $project,
            $selectedSources,
            $parseResult,
            $initialSemantic,
        );

        if (!$preparation->isSuccessful || $preparation->project === null) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($initialSemantic->diagnostics);
            $diagnostics->addAll($preparation->diagnostics);

            return new ProjectCheckResult($parseResult, $initialSemantic, null, $diagnostics);
        }

        $semanticResult = $this->semanticAnalyzer->analyze($parseResult, $preparation->contextParseResult);

        if (!$semanticResult->isSuccessful) {
            return new ProjectCheckResult($parseResult, $semanticResult, null, $semanticResult->diagnostics);
        }

        $backendResult = $this->backend->analyze($preparation->project);
        $combined = new DiagnosticBag();
        $combined->addAll($semanticResult->diagnostics);
        $combined->addAll($backendResult->diagnostics);
        $diagnostics = $this->deduplicator->deduplicate($combined);
        $finalBackend = new AnalysisResult($diagnostics, $backendResult->metadata);

        return new ProjectCheckResult($parseResult, $semanticResult, $finalBackend, $diagnostics);
    }
}
