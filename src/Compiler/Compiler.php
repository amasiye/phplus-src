<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler;

use Amasiye\Ppphp\Compiler\Enumerations\CompilationFailureKind;
use Amasiye\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Amasiye\Ppphp\Compiler\Output\OutputPlanner;
use Amasiye\Ppphp\Compiler\Output\ProjectBuildLock;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectChecker;
use Amasiye\Ppphp\Project\ProjectSelection;
use Amasiye\Ppphp\Transpilation\Emission\ProductionPhpEmitter;

final readonly class Compiler
{
    public const string NAME = 'ppphp';

    public const string VERSION = 'dev-2026.3.1';

    public const int LOWERING_FORMAT_VERSION = 1;

    public function __construct(
        private ProjectChecker $checker = new ProjectChecker(),
        private OutputPlanner $outputPlanner = new OutputPlanner(),
        private ProductionPhpEmitter $emitter = new ProductionPhpEmitter(),
        private AtomicBuildCommitter $committer = new AtomicBuildCommitter(),
        private ComposerRuntimeConfigurator $composerRuntimeConfigurator = new ComposerRuntimeConfigurator(),
        private ProjectBuildLock $buildLock = new ProjectBuildLock(),
    ) {}

    public function compile(Project $project, ProjectSelection $selection): CompilationResult
    {
        try {
            $acquired = $this->buildLock->acquire($project->configuration);
        } catch (\Throwable $exception) {
            return $this->createOutputFailure(new Diagnostic(
                DiagnosticCode::BuildCouldNotBeStaged,
                'The compiler could not create the project build lock.',
                help: 'Check that the configured cache path is writable and is not a symbolic link.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
        }

        if (!$acquired) {
            return $this->createOutputFailure(new Diagnostic(
                DiagnosticCode::BuildIsAlreadyInProgress,
                'Another build or clean transaction already owns this project build lock.',
                help: 'Wait for the active compiler operation to finish, then run the command again.',
            ));
        }

        try {
            $check = $this->checker->check($project, $selection->analysisSources);
            $diagnostics = new DiagnosticBag();
            $diagnostics->addAll($check->diagnostics);

            if (!$check->isSuccessful) {
                return new CompilationResult([], null, 0, false, CompilationFailureKind::Source, $diagnostics);
            }

            $plan = $this->outputPlanner->plan($project, $selection->outputSources);
            $diagnostics->addAll($plan->diagnostics);

            if (!$plan->isSuccessful || $plan->plan === null) {
                return new CompilationResult([], null, 0, false, CompilationFailureKind::Output, $diagnostics);
            }

            $this->addComposerWarnings($project, $diagnostics);
            $artifacts = $this->emitter->emit($project, $check, $plan->plan);
            $commit = $this->committer->commit($project, $selection, $artifacts);
            $diagnostics->addAll($commit->diagnostics);

            if (!$commit->committed || $commit->manifest === null) {
                return new CompilationResult($artifacts, null, 0, false, CompilationFailureKind::Output, $diagnostics);
            }

            return new CompilationResult(
                $artifacts,
                $commit->manifest,
                $commit->staleRemovalCount,
                true,
                null,
                $diagnostics,
            );
        } finally {
            $this->buildLock->release();
        }
    }

    private function addComposerWarnings(Project $project, DiagnosticBag $diagnostics): void
    {
        if ($project->composer->configurationPath === null) {
            return;
        }

        $projection = $this->composerRuntimeConfigurator->project($project->configuration);

        foreach ($projection->unprojectedMappings as $mapping) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::ComposerAutoloadDoesNotTargetBuildOutput,
                sprintf(
                    'Composer entry "%s.%s" still targets source path "%s"; its generated runtime path is "%s".',
                    $mapping->section,
                    $mapping->entry,
                    $mapping->sourcePath,
                    $mapping->expectedPath,
                ),
                help: 'Run ppphp composer:configure, then composer update --lock and composer dump-autoload.',
            ));
        }
    }

    private function createOutputFailure(Diagnostic $diagnostic): CompilationResult
    {
        $diagnostics = new DiagnosticBag();
        $diagnostics->add($diagnostic);

        return new CompilationResult([], null, 0, false, CompilationFailureKind::Output, $diagnostics);
    }
}
