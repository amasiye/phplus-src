<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Amasiye\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\DiagnosticProcessor;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;

final readonly class ProjectChecker
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private AnalysisWorkspacePreparer $workspacePreparer = new AnalysisWorkspacePreparer(),
        private ProjectAnalyzer $backend = new PhpStanProjectAnalyzer(),
        private DiagnosticProcessor $diagnosticProcessor = new DiagnosticProcessor(),
    ) {}

    public function check(Project $project, SourceSet $selectedSources): ProjectCheckResult
    {
        $preparation = $this->prepare($project, $selectedSources);

        if (!$preparation->isSuccessful || $preparation->analysisProject === null) {
            return new ProjectCheckResult(
                $preparation->parseResult,
                $preparation->semanticResult,
                null,
                $preparation->diagnostics,
            );
        }

        return $this->complete(
            $preparation,
            $this->backend->analyze($preparation->analysisProject),
        );
    }

    public function prepare(Project $project, SourceSet $selectedSources): ProjectCheckPreparation
    {
        $parseResult = $this->syntaxChecker->check($project, $selectedSources);

        if (!$parseResult->isSuccessful) {
            return new ProjectCheckPreparation(
                $parseResult,
                null,
                null,
                $this->diagnosticProcessor->process($parseResult->diagnostics),
            );
        }

        $declarationContext = $this->workspacePreparer->collectDeclarationContext(
            $project,
            $selectedSources,
        );
        $initialSemantic = $this->semanticAnalyzer->analyze($parseResult, $declarationContext);

        if (!$initialSemantic->isSuccessful) {
            return new ProjectCheckPreparation(
                $parseResult,
                $initialSemantic,
                null,
                $this->diagnosticProcessor->process($initialSemantic->diagnostics),
            );
        }

        $preparation = $this->workspacePreparer->prepare(
            $project,
            $selectedSources,
            $parseResult,
            $initialSemantic,
            $declarationContext,
        );

        if (!$preparation->isSuccessful || $preparation->project === null) {
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($initialSemantic->diagnostics);
            $diagnostics->addAll($preparation->diagnostics);

            return new ProjectCheckPreparation(
                $parseResult,
                $initialSemantic,
                null,
                $this->diagnosticProcessor->process($diagnostics),
            );
        }

        $semanticResult = $this->semanticAnalyzer->analyze($parseResult, $preparation->contextParseResult);

        if (!$semanticResult->isSuccessful) {
            return new ProjectCheckPreparation(
                $parseResult,
                $semanticResult,
                null,
                $this->diagnosticProcessor->process($semanticResult->diagnostics),
            );
        }

        return new ProjectCheckPreparation(
            $parseResult,
            $semanticResult,
            $preparation->project,
            $semanticResult->diagnostics,
        );
    }

    public function complete(
        ProjectCheckPreparation $preparation,
        AnalysisResult $backendResult,
    ): ProjectCheckResult {
        if (!$preparation->isSuccessful || $preparation->semanticResult === null) {
            throw new \LogicException('A project check can only complete after successful preparation.');
        }

        $combined = new DiagnosticBag();
        $combined->addAll($preparation->semanticResult->diagnostics);
        $combined->addAll($backendResult->diagnostics);
        $diagnostics = $this->diagnosticProcessor->process($combined);
        $finalBackend = new AnalysisResult($diagnostics, $backendResult->metadata);

        return new ProjectCheckResult(
            $preparation->parseResult,
            $preparation->semanticResult,
            $finalBackend,
            $diagnostics,
        );
    }
}
