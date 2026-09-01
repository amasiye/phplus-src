<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Parity;

use Amasiye\Ppphp\Analysis\Capability\AnalysisCapability;
use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Amasiye\Ppphp\Analysis\Capability\CompilerCoverage;
use Amasiye\Ppphp\Analysis\CompilerProjectAnalyzer;
use Amasiye\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\ProjectChecker;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelector;

final class AnalyzerParityRunner
{
    private readonly CompilerProjectAnalyzer $compilerAnalyzer;
    private readonly ProjectChecker $checker;

    public function __construct(
        private readonly AnalyzerParityFixtureRepository $fixtures = new AnalyzerParityFixtureRepository(),
        private readonly AnalysisCapabilityCatalog $catalog = new AnalysisCapabilityCatalog(),
        private readonly ProjectConfigLoader $configLoader = new ProjectConfigLoader(),
        private readonly ProjectLoader $projectLoader = new ProjectLoader(),
        private readonly ProjectSelector $selector = new ProjectSelector(),
        private readonly DiagnosticSetDiffer $differ = new DiagnosticSetDiffer(),
        ?CompilerProjectAnalyzer $compilerAnalyzer = null,
        ?ProjectChecker $checker = null,
    ) {
        $this->compilerAnalyzer = $compilerAnalyzer ?? new CompilerProjectAnalyzer();
        $this->checker = $checker ?? new ProjectChecker($this->compilerAnalyzer);
    }

    public function run(string $fixtureManifest): AnalyzerParityReport
    {
        $scenarios = $this->fixtures->load($fixtureManifest);
        $capabilities = $this->catalog->all;
        $capabilityIds = array_fill_keys(array_map(static fn (AnalysisCapability $capability): string => $capability->id, $capabilities), true);
        $unexpectedCompiler = [];
        $unexpectedFull = [];
        $expectationFailures = [];
        $results = [];

        foreach ($scenarios as $scenario) {
            if (!isset($capabilityIds[$scenario->capabilityId])) {
                throw new \RuntimeException(sprintf('Analyzer parity scenario "%s" references an unknown capability.', $scenario->id));
            }

            $result = $this->runScenario($scenario);
            array_push($unexpectedCompiler, ...$result['unexpectedCompiler']);
            array_push($unexpectedFull, ...$result['unexpectedFull']);
            array_push($expectationFailures, ...$result['expectationFailures']);
            $results[] = $result['payload'];
        }

        $coverage = ['complete' => 0, 'partial' => 0, 'backendOnly' => 0];

        foreach ($capabilities as $capability) {
            match ($capability->compilerCoverage) {
                CompilerCoverage::Complete => $coverage['complete']++,
                CompilerCoverage::Partial => $coverage['partial']++,
                CompilerCoverage::BackendOnly => $coverage['backendOnly']++,
            };
        }

        return new AnalyzerParityReport([
            'version' => 1,
            'catalogVersion' => AnalysisCapabilityCatalog::VERSION,
            'compiler' => [
                'name' => Compiler::NAME,
                'version' => Compiler::VERSION,
                'loweringFormatVersion' => Compiler::LOWERING_FORMAT_VERSION,
            ],
            'scenarioCount' => count($scenarios),
            'capabilityCount' => count($capabilities),
            'coverage' => $coverage,
            'fullParity' => $this->catalog->uncoveredRequiredCapabilityIds === [],
            'requiredGaps' => $this->catalog->uncoveredRequiredCapabilityIds,
            'unexpectedCompilerDiagnostics' => $unexpectedCompiler,
            'unexpectedFullDiagnostics' => $unexpectedFull,
            'expectationFailures' => $expectationFailures,
            'scenarios' => $results,
        ]);
    }

    /**
     * @return array{
     *   payload: array<string, mixed>,
     *   unexpectedCompiler: list<array<string, mixed>>,
     *   unexpectedFull: list<array<string, mixed>>,
     *   expectationFailures: list<array<string, mixed>>
     * }
     */
    private function runScenario(AnalyzerParityScenario $scenario): array
    {
        $root = $this->createProject($scenario);

        try {
            $config = $this->configLoader->load($root, requireSourceDirectories: true);

            if (!$config->isSuccessful || $config->configuration === null) {
                throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" has an invalid project configuration.', $scenario->id));
            }

            $project = $this->projectLoader->load($config->configuration);

            if (!$project->isSuccessful || $project->project === null) {
                throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" could not be loaded.', $scenario->id));
            }

            $selection = $this->selector->select($project->project, $scenario->selection, SelectionMode::Check);

            if (!$selection->isSuccessful || $selection->selection === null) {
                throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" has an invalid selection.', $scenario->id));
            }

            $compiler = $this->compilerAnalyzer->analyze($project->project, $selection->selection->analysisSources);
            $checker = $scenario->backendUnavailable
                ? new ProjectChecker(
                    $this->compilerAnalyzer,
                    backend: new PhpStanProjectAnalyzer($root . '/missing-compiler'),
                )
                : $this->checker;
            $full = $checker->check($project->project, $selection->selection->analysisSources);
            $compilerDiagnostics = $this->fingerprints($compiler->diagnostics);
            $fullDiagnostics = $this->fingerprints($full->diagnostics);
            $compilerOnly = $this->differ->subtract($compilerDiagnostics, $fullDiagnostics);
            $fullOnly = $this->differ->subtract($fullDiagnostics, $compilerDiagnostics);
            $actualCompilerCodes = $this->codes($compilerDiagnostics);
            $actualFullCodes = $this->codes($fullDiagnostics);
            $unexpectedCompiler = $this->unexpected($scenario->id, $compilerDiagnostics, $scenario->expectedCompilerDiagnostics);
            $unexpectedFull = $this->unexpected($scenario->id, $fullDiagnostics, $scenario->expectedFullDiagnostics);
            $expectationFailures = [];

            if ($actualCompilerCodes !== $scenario->expectedCompilerDiagnostics) {
                $expectationFailures[] = [
                    'scenario' => $scenario->id,
                    'engine' => 'compiler',
                    'expected' => $scenario->expectedCompilerDiagnostics,
                    'actual' => $actualCompilerCodes,
                ];
            }

            if ($actualFullCodes !== $scenario->expectedFullDiagnostics) {
                $expectationFailures[] = [
                    'scenario' => $scenario->id,
                    'engine' => 'full',
                    'expected' => $scenario->expectedFullDiagnostics,
                    'actual' => $actualFullCodes,
                ];
            }

            $observedDisagreement = $fullOnly === [] && $compilerOnly === []
                ? null
                : ($scenario->expectedDisagreement ?? OracleDisagreement::FixtureError);

            if ($observedDisagreement !== $scenario->expectedDisagreement) {
                $expectationFailures[] = [
                    'scenario' => $scenario->id,
                    'engine' => 'differential',
                    'expected' => $scenario->expectedDisagreement?->value,
                    'actual' => $observedDisagreement?->value,
                ];
            }

            return [
                'payload' => [
                    'id' => $scenario->id,
                    'capabilityId' => $scenario->capabilityId,
                    'selection' => $scenario->selection,
                    'releaseBlocking' => $scenario->releaseBlocking,
                    'expectedDisagreement' => $scenario->expectedDisagreement?->value,
                    'observedDisagreement' => $observedDisagreement?->value,
                    'compilerDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $compilerDiagnostics),
                    'fullDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $fullDiagnostics),
                    'compilerOnlyDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $compilerOnly),
                    'fullOnlyDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $fullOnly),
                ],
                'unexpectedCompiler' => $unexpectedCompiler,
                'unexpectedFull' => $unexpectedFull,
                'expectationFailures' => $expectationFailures,
            ];
        } finally {
            $this->removeProject($root);
        }
    }

    /** @return list<DiagnosticFingerprint> */
    private function fingerprints(DiagnosticBag $diagnostics): array
    {
        $items = array_map(DiagnosticFingerprint::fromDiagnostic(...), $diagnostics->toArray());
        usort($items, static fn (DiagnosticFingerprint $left, DiagnosticFingerprint $right): int => $left->key <=> $right->key);

        return $items;
    }

    /**
     * @param list<DiagnosticFingerprint> $diagnostics
     * @return list<string>
     */
    private function codes(array $diagnostics): array
    {
        $codes = array_map(static fn (DiagnosticFingerprint $item): string => $item->code, $diagnostics);
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * @param list<DiagnosticFingerprint> $diagnostics
     * @param list<string> $expectedCodes
     * @return list<array<string, mixed>>
     */
    private function unexpected(string $scenarioId, array $diagnostics, array $expectedCodes): array
    {
        $available = array_count_values($expectedCodes);
        $unexpected = [];

        foreach ($diagnostics as $diagnostic) {
            if (($available[$diagnostic->code] ?? 0) > 0) {
                $available[$diagnostic->code]--;
                continue;
            }

            $unexpected[] = ['scenario' => $scenarioId, 'diagnostic' => $diagnostic->toArray()];
        }

        return $unexpected;
    }

    private function createProject(AnalyzerParityScenario $scenario): string
    {
        $root = sys_get_temp_dir() . '/ppphp-analyzer-parity-' . bin2hex(random_bytes(8));

        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new \RuntimeException('The analyzer parity project directory could not be created.');
        }

        $this->writeFile($root . '/ppphp.json', json_encode([
            'source' => ['src'],
            'output' => 'build/ppphp',
            'cache' => '.ppphp-cache',
            'targetPhpVersion' => '8.4',
            'stubs' => $scenario->stubs === [] ? [] : ['stubs'],
            'exclude' => ['vendor', 'build', '.ppphp-cache'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        foreach ($scenario->sources as $path => $contents) {
            $this->writeFile($root . '/src/' . $path, $contents);
        }

        foreach ($scenario->stubs as $path => $contents) {
            $this->writeFile($root . '/stubs/' . $path, $contents);
        }

        foreach ($scenario->projectFiles as $path => $contents) {
            $this->writeFile($root . '/' . $path, $contents);
        }

        return $root;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('An analyzer parity fixture directory could not be created.');
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('An analyzer parity fixture file could not be written.');
        }
    }

    private function removeProject(string $root): void
    {
        if (!str_starts_with($root, sys_get_temp_dir() . '/ppphp-analyzer-parity-') || !is_dir($root)) {
            return;
        }

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $path = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($root);
    }

    private function removeDirectory(string $path): void
    {
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entry->isDir() && !$entry->isLink()
                ? $this->removeDirectory($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
