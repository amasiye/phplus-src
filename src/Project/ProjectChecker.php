<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Amasiye\Ppphp\Analysis\CompilerProjectAnalyzer;
use Amasiye\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\DiagnosticProcessor;

final readonly class ProjectChecker
{
    public function __construct(
        private CompilerProjectAnalyzer $compilerAnalyzer = new CompilerProjectAnalyzer(),
        private AnalysisWorkspacePreparer $workspacePreparer = new AnalysisWorkspacePreparer(),
        private ?ProjectAnalyzer $backend = null,
        private DiagnosticProcessor $diagnosticProcessor = new DiagnosticProcessor(),
    ) {}

    public function check(Project $project, SourceSet $selectedSources): ProjectCheckResult
    {
        $preparation = $this->prepare($project, $selectedSources);

        if (!$preparation->isSuccessful || $preparation->analysisProject === null) {
            return new ProjectCheckResult(
                $preparation->compilerAnalysis->parseResult,
                $preparation->compilerAnalysis->semanticResult,
                null,
                $preparation->diagnostics,
            );
        }

        return $this->complete(
            $preparation,
            ($this->backend ?? new PhpStanProjectAnalyzer())->analyze($preparation->analysisProject),
        );
    }

    public function prepare(Project $project, SourceSet $selectedSources): SupplementalAnalysisPreparation
    {
        $compilerAnalysis = $this->compilerAnalyzer->analyze($project, $selectedSources);

        if (!$compilerAnalysis->isSuccessful) {
            return new SupplementalAnalysisPreparation(
                $compilerAnalysis,
                null,
                $compilerAnalysis->diagnostics,
            );
        }

        $preparation = $this->workspacePreparer->prepare($compilerAnalysis);

        if (!$preparation->isSuccessful || $preparation->project === null) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($compilerAnalysis->diagnostics);
            $diagnostics->addAll($preparation->diagnostics);

            return new SupplementalAnalysisPreparation(
                $compilerAnalysis,
                null,
                $this->diagnosticProcessor->process($diagnostics),
            );
        }

        return new SupplementalAnalysisPreparation(
            $compilerAnalysis,
            $preparation->project,
            $compilerAnalysis->diagnostics,
        );
    }

    public function complete(
        SupplementalAnalysisPreparation $preparation,
        AnalysisResult $backendResult,
    ): ProjectCheckResult {
        if (!$preparation->isSuccessful || $preparation->compilerAnalysis->semanticResult === null) {
            throw new \LogicException('A project check can only complete after successful preparation.');
        }

        $combined = new DiagnosticBag();
        $combined->addAll($preparation->compilerAnalysis->diagnostics);
        $combined->addAll($backendResult->diagnostics);
        $diagnostics = $this->diagnosticProcessor->process($combined);
        $finalBackend = new AnalysisResult($diagnostics, $backendResult->metadata);

        return new ProjectCheckResult(
            $preparation->compilerAnalysis->parseResult,
            $preparation->compilerAnalysis->semanticResult,
            $finalBackend,
            $diagnostics,
        );
    }
}
