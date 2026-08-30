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
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Support\Path;

final readonly class PhpStanProjectAnalyzer implements ProjectAnalyzer
{
    private string $compilerRoot;

    public function __construct(
        ?string $compilerRoot = null,
        private PhpStanProcessRunner $runner = new PhpStanProcessRunner(),
        private PhpStanResultParser $parser = new PhpStanResultParser(),
        private PhpStanDiagnosticMapper $mapper = new PhpStanDiagnosticMapper(),
        private float $timeout = 60.0,
    ) {
        $this->compilerRoot = Path::normalize($compilerRoot ?? dirname(__DIR__, 3));
    }

    public function analyze(AnalysisProject $project): AnalysisResult
    {
        $diagnostics = new DiagnosticBag();

        if ($project->selectedFiles === []) {
            return new AnalysisResult($diagnostics, ['backend' => 'phpstan', 'skipped' => true]);
        }

        $executable = Path::join($this->compilerRoot, 'vendor/phpstan/phpstan/phpstan');

        if (!is_file($executable)) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'Static Analysis Backend Failed',
                'The compiler-pinned static-analysis backend is not installed.',
                ['executable' => $executable],
            );

            return new AnalysisResult($diagnostics);
        }

        try {
            $configuration = (new PhpStanConfigBuilder($this->compilerRoot))->build($project);
            $command = [
                PHP_BINARY,
                $executable,
                'analyse',
                '--configuration=' . $configuration,
                '--error-format=json',
                '--no-progress',
                '--memory-limit=1G',
            ];
            $process = $this->runner->run($command, $project->workspaceRoot, $this->timeout);
            @file_put_contents(Path::join($project->workspaceRoot, 'result.json'), $process->stdout);

            if ($process->timedOut) {
                throw new PhpStanExecutionException('The static-analysis backend exceeded its time limit.');
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
            $title = $code === DiagnosticCode::StaticAnalysisResultInvalid
                ? 'Static Analysis Result Is Invalid'
                : 'Static Analysis Backend Failed';
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                $code,
                $title,
                'The compiler could not complete isolated static analysis.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        } catch (\Throwable $exception) {
            $this->addInfrastructureDiagnostic(
                $diagnostics,
                DiagnosticCode::StaticAnalysisBackendFailed,
                'Static Analysis Backend Failed',
                'The compiler could not start its isolated static-analysis backend.',
                ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );

            return new AnalysisResult($diagnostics);
        }
    }

    /** @param array<string, mixed> $debug */
    private function addInfrastructureDiagnostic(
        DiagnosticBag $diagnostics,
        DiagnosticCode $code,
        string $title,
        string $message,
        array $debug,
    ): void {
        $diagnostics->add(new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            help: 'Run the command again with --debug for backend details.',
            debug: $debug,
        ));
    }
}
