<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Cache\CompilerCache;
use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Amasiye\Ppphp\Analysis\CompilerProjectAnalyzer;
use Amasiye\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticProcessor;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Compiler\Output\ProjectBuildLock;

final readonly class ProjectChecker
{
    public function __construct(
        private CompilerProjectAnalyzer $compilerAnalyzer = new CompilerProjectAnalyzer(),
        private AnalysisWorkspacePreparer $workspacePreparer = new AnalysisWorkspacePreparer(),
        private ?ProjectAnalyzer $backend = null,
        private DiagnosticProcessor $diagnosticProcessor = new DiagnosticProcessor(),
        private CompilerCache $cache = new CompilerCache(),
        private ProjectBuildLock $operationLock = new ProjectBuildLock(),
    ) {}

    public function check(
        Project $project,
        SourceSet $selectedSources,
        bool $coordinate = true,
        bool $allowCachedEvidence = true,
    ): ProjectCheckResult
    {
        if ($coordinate) {
            try {
                $acquired = $this->operationLock->acquire($project->configuration, false);
            } catch (\Throwable $exception) {
                return $this->lockFailure(
                    'The compiler could not create the stable project operation lock for checking.',
                    $exception,
                );
            }

            if (!$acquired) {
                return $this->lockFailure('The project is being changed by another compiler operation.');
            }
        }

        try {
            $snapshot = $this->cache->snapshot($project, $selectedSources);

            if ($allowCachedEvidence && $this->backend === null) {
                $cached = $this->cache->loadCheck($project, $selectedSources, $snapshot);

                if ($cached !== null) {
                    return $cached;
                }
            }

            $preparation = $this->prepare($project, $selectedSources);
            $this->cache->storeCompilerAnalysis($project, $snapshot, $preparation->compilerAnalysis);

            if (!$preparation->isSuccessful || $preparation->analysisProject === null) {
                return new ProjectCheckResult(
                    $preparation->compilerAnalysis->parseResult,
                    $preparation->compilerAnalysis->semanticResult,
                    null,
                    $preparation->diagnostics,
                    cacheStatistics: $this->cache->statistics,
                    declarationContext: $preparation->compilerAnalysis->declarationContext,
                );
            }

            $completed = $this->complete(
                $preparation,
                ($this->backend ?? new PhpStanProjectAnalyzer())->analyze($preparation->analysisProject),
            );
            $result = new ProjectCheckResult(
                $completed->parseResult,
                $completed->semanticResult,
                $completed->backendResult,
                $completed->diagnostics,
                $completed->completeness,
                $completed->uncoveredRequiredCapabilities,
                cacheStatistics: $this->cache->statistics,
                declarationContext: $completed->declarationContext,
            );

            if ($this->backend === null) {
                $this->cache->storeSupplementalResult($project, $snapshot, $result);
            }

            return $result;
        } finally {
            if ($coordinate) {
                $this->operationLock->release();
            }
        }
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
            declarationContext: $preparation->compilerAnalysis->declarationContext,
        );
    }

    private function lockFailure(string $message, ?\Throwable $exception = null): ProjectCheckResult
    {
        $diagnostics = new DiagnosticBag();
        $diagnostics->add(new Diagnostic(
            $exception === null ? DiagnosticCode::BuildIsAlreadyInProgress : DiagnosticCode::BuildCouldNotBeStaged,
            $message,
            help: $exception === null
                ? 'Wait for the active build or clean operation to finish, then check again.'
                : 'Check that the project root is writable and .ppphp-operation.lock is not a symbolic link.',
            debug: $exception === null ? [] : [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ));

        return new ProjectCheckResult(
            null,
            null,
            null,
            $diagnostics,
            compilerEvidence: false,
            supplementalEvidence: false,
            cacheStatistics: $this->cache->statistics,
        );
    }
}
