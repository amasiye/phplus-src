<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Analysis\AnalysisResult;
use Amasiye\Ppphp\Analysis\Interfaces\ProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\Exceptions\PhpStanExecutionException;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Amasiye\Ppphp\Support\Path;

final readonly class PhpStanProjectAnalyzer implements ProjectAnalyzer
{
    private PhpStanAnalysisPlanBuilder $planBuilder;

    public function __construct(
        ?string $compilerRoot = null,
        private PhpStanProcessRunner $runner = new PhpStanProcessRunner(),
        private PhpStanResultParser $parser = new PhpStanResultParser(),
        private PhpStanDiagnosticMapper $mapper = new PhpStanDiagnosticMapper(),
        private float $timeout = 60.0,
        ?PhpStanAnalysisPlanBuilder $planBuilder = null,
    ) {
        $this->planBuilder = $planBuilder ?? new PhpStanAnalysisPlanBuilder($compilerRoot);
    }

    public function analyze(AnalysisProject $project): AnalysisResult
    {
        $diagnostics = new DiagnosticBag();

        if ($project->selectedFiles === []) {
            return new AnalysisResult($diagnostics, ['backend' => 'phpstan', 'skipped' => true]);
        }

        $executable = $this->planBuilder->executablePath();

        if (!is_file($executable)) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'The compiler-pinned static-analysis backend is not installed.',
                ['executable' => $executable],
            );

            return new AnalysisResult($diagnostics);
        }

        try {
            $plan = $this->buildPlan($project);
            $process = $this->runner->run($plan->command, $plan->workingDirectory, $this->timeout);
        } catch (PhpStanExecutionException $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'The compiler could not start its isolated static-analysis process.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        } catch (\Throwable $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'The compiler could not start its isolated static-analysis process.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        }

        return $this->complete($project, $process);
    }

    public function buildPlan(
        AnalysisProject $project,
        bool $debug = false,
        ?string $phpExecutable = null,
    ): PhpStanAnalysisPlan {
        return $this->planBuilder->build($project, $debug, $phpExecutable);
    }

    public function complete(AnalysisProject $project, PhpStanProcessResult $process): AnalysisResult
    {
        $diagnostics = new DiagnosticBag();
        @file_put_contents(Path::join($project->workspaceRoot, 'result.json'), $process->stdout);

        try {
            if ($process->timedOut) {
                throw new PhpStanExecutionException('The static-analysis backend exceeded its time limit.');
            }

            if ($process->outputLimitExceeded) {
                throw new PhpStanExecutionException('The static-analysis backend exceeded its output limit.');
            }

            if ($process->executionFailure !== null) {
                throw new PhpStanExecutionException('The static-analysis backend process failed to complete.');
            }

            if (!in_array($process->exitCode, [0, 1], true)) {
                throw new PhpStanExecutionException(sprintf('The static-analysis backend exited with status %d.', $process->exitCode));
            }

            $parsed = $this->parser->parse($process->stdout);

            if ($parsed->globalErrors !== []) {
                throw new PhpStanExecutionException('The static-analysis backend reported a global execution error.');
            }

            foreach ($parsed->findings as $finding) {
                $diagnostic = $this->mapper->map($finding, $project);

                if ($diagnostic !== null) {
                    $diagnostics->add($diagnostic);
                }
            }

            return new AnalysisResult($diagnostics, [
                'backend' => 'phpstan',
                'exitCode' => $process->exitCode,
                'stderr' => $process->stderr,
                'command' => $process->command,
            ]);
        } catch (PhpStanExecutionException $exception) {
            $code = str_contains(strtolower($exception->getMessage()), 'json')
                || str_contains(strtolower($exception->getMessage()), 'result')
                ? DiagnosticCode::StaticAnalysisResultInvalid
                : DiagnosticCode::StaticAnalysisBackendFailed;
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                $code,
                'The compiler could not complete isolated static analysis.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        } catch (\Throwable $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'The compiler could not complete isolated static analysis.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        }
    }

    /** @param array<string, mixed> $debug */
    private function addInfrastructureDiagnostic(
        DiagnosticBag $diagnostics,
        DiagnosticCode $code,
        string $message,
        array $debug,
    ): void {
        $diagnostics->add(new Diagnostic(
            $code,
            $message,
            help: 'Run the command again with --debug for analysis details.',
            debug: $debug,
            origin: DiagnosticOrigin::Subprocess,
        ));
    }
}
