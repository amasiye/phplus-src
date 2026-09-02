<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Parity;

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapability;
use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Analysis\Capability\CompilerCoverage;
use Atatusoft\Ppphp\Analysis\CompilerProjectAnalyzer;
use Atatusoft\Ppphp\Analysis\PhpStan\PhpStanProjectAnalyzer;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Interop\Composer\Declaration\PortableDependencyIndexProvider;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Atatusoft\Ppphp\Project\Enumerations\SelectionMode;
use Atatusoft\Ppphp\Project\ProjectChecker;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSelector;

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
        $supplementalDifferenceCount = 0;
        $optionalDifferenceCount = 0;

        foreach ($scenarios as $scenario) {
            if (!isset($capabilityIds[$scenario->capabilityId])) {
                throw new \RuntimeException(sprintf('Analyzer parity scenario "%s" references an unknown capability.', $scenario->id));
            }

            $result = $this->runScenario($scenario);
            array_push($unexpectedCompiler, ...$result['unexpectedCompiler']);
            array_push($unexpectedFull, ...$result['unexpectedFull']);
            array_push($expectationFailures, ...$result['expectationFailures']);
            $supplementalDifferenceCount += $result['supplementalDifferenceCount'];
            $optionalDifferenceCount += $result['optionalDifferenceCount'];
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
            'version' => 2,
            'catalogVersion' => AnalysisCapabilityCatalog::VERSION,
            'scenarioSchemaVersion' => AnalyzerParityScenario::SCHEMA_VERSION,
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
            'supplementalDifferenceCount' => $supplementalDifferenceCount,
            'optionalDifferenceCount' => $optionalDifferenceCount,
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
     *   expectationFailures: list<array<string, mixed>>,
     *   supplementalDifferenceCount: int,
     *   optionalDifferenceCount: int
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

            $checker = $scenario->backendUnavailable
                ? new ProjectChecker(
                    $this->compilerAnalyzer,
                    backend: new PhpStanProjectAnalyzer($root . '/missing-compiler'),
                )
                : $this->checker;
            if ($scenario->portableDependencyIndex) {
                $installed = $this->compilerAnalyzer->analyze($project->project, $selection->selection->analysisSources);

                if ($installed->diagnostics->hasErrors) {
                    throw new \RuntimeException(sprintf('Analyzer parity fixture "%s" could not build its dependency index.', $scenario->id));
                }

                $indexRoot = $root . '/portable-index';
                (new DependencyDeclarationIndexWriter())->write(
                    $project->project->composer,
                    $installed->declarationContext,
                    $project->project->configuration->targetPhpVersion,
                    $indexRoot,
                );
                $full = $checker->check($project->project, $selection->selection->analysisSources);

                foreach ($installed->declarationContext->sourceFiles as $sourceFile) {
                    if ($sourceFile->dependencyProvenance !== null && is_file($sourceFile->path)) {
                        unlink($sourceFile->path);
                    }
                }

                $compiler = $this->compilerAnalyzer->analyze(
                    $project->project,
                    $selection->selection->analysisSources,
                    new PortableDependencyIndexProvider($indexRoot . '/manifest.json'),
                );
            } else {
                $compiler = $this->compilerAnalyzer->analyze($project->project, $selection->selection->analysisSources);
                $full = $checker->check($project->project, $selection->selection->analysisSources);
            }
            $compilerDiagnostics = $this->fingerprints($compiler->diagnostics);
            $fullDiagnostics = $this->fingerprints($full->diagnostics);
            $actualCompilerCodes = $this->codes($compilerDiagnostics);
            $unexpectedCompiler = $this->unexpected($scenario->id, $compilerDiagnostics, $scenario->expectedCompilerDiagnostics);
            $partition = $this->partitionFullDiagnostics($fullDiagnostics, $scenario);
            $requiredFullCodes = $this->codes($partition['required']);
            $supplementalFullCodes = $this->codes($partition['supplemental']);
            $optionalFullCodes = $this->codes($partition['optional']);
            $compilerOnlyCodes = $this->codes($this->differ->subtract($compilerDiagnostics, $partition['required']));
            $fullOnlyCodes = $this->codes($this->differ->subtract($partition['required'], $compilerDiagnostics));
            $unexpectedFull = array_map(
                static fn (DiagnosticFingerprint $diagnostic): array => [
                    'scenario' => $scenario->id,
                    'diagnostic' => $diagnostic->toArray(),
                ],
                $partition['unexpected'],
            );
            $expectationFailures = [];

            if ($actualCompilerCodes !== $scenario->expectedCompilerDiagnostics) {
                $expectationFailures[] = [
                    'scenario' => $scenario->id,
                    'engine' => 'compiler',
                    'expected' => $scenario->expectedCompilerDiagnostics,
                    'actual' => $actualCompilerCodes,
                ];
            }

            foreach ([
                'required-full' => [$scenario->expectedRequiredFullDiagnostics, $requiredFullCodes],
                'supplemental-full' => [$scenario->expectedSupplementalFullDiagnostics, $supplementalFullCodes],
                'optional-full' => [$scenario->expectedOptionalDiagnostics, $optionalFullCodes],
            ] as $engine => [$expected, $actual]) {
                if ($actual === $expected) {
                    continue;
                }

                $expectationFailures[] = [
                    'scenario' => $scenario->id,
                    'engine' => $engine,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }

            $observedDisagreement = $this->classifyDisagreement(
                $scenario,
                $compilerOnlyCodes,
                $fullOnlyCodes,
                $partition['supplemental'],
                $partition['optional'],
            );

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
                    'requiredCompilerDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $compilerDiagnostics),
                    'requiredFullDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $partition['required']),
                    'supplementalFullDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $partition['supplemental']),
                    'optionalFullDiagnostics' => array_map(static fn (DiagnosticFingerprint $item): array => $item->toArray(), $partition['optional']),
                    'requiredCompilerOnlyCodes' => $compilerOnlyCodes,
                    'requiredFullOnlyCodes' => $fullOnlyCodes,
                ],
                'unexpectedCompiler' => $unexpectedCompiler,
                'unexpectedFull' => $unexpectedFull,
                'expectationFailures' => $expectationFailures,
                'supplementalDifferenceCount' => count($partition['supplemental']),
                'optionalDifferenceCount' => count($partition['optional']),
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

    /**
     * @param list<DiagnosticFingerprint> $diagnostics
     * @return array{
     *   required: list<DiagnosticFingerprint>,
     *   supplemental: list<DiagnosticFingerprint>,
     *   optional: list<DiagnosticFingerprint>,
     *   unexpected: list<DiagnosticFingerprint>
     * }
     */
    private function partitionFullDiagnostics(array $diagnostics, AnalyzerParityScenario $scenario): array
    {
        $inventories = [
            'required' => array_count_values($scenario->expectedRequiredFullDiagnostics),
            'supplemental' => array_count_values($scenario->expectedSupplementalFullDiagnostics),
            'optional' => array_count_values($scenario->expectedOptionalDiagnostics),
        ];
        $partition = ['required' => [], 'supplemental' => [], 'optional' => [], 'unexpected' => []];

        foreach ($diagnostics as $diagnostic) {
            foreach (['required', 'supplemental', 'optional'] as $group) {
                if (($inventories[$group][$diagnostic->code] ?? 0) === 0) {
                    continue;
                }

                $inventories[$group][$diagnostic->code]--;
                $partition[$group][] = $diagnostic;
                continue 2;
            }

            $partition['unexpected'][] = $diagnostic;
        }

        return $partition;
    }

    /**
     * @param list<string> $compilerOnlyCodes
     * @param list<string> $fullOnlyCodes
     * @param list<DiagnosticFingerprint> $supplemental
     * @param list<DiagnosticFingerprint> $optional
     */
    private function classifyDisagreement(
        AnalyzerParityScenario $scenario,
        array $compilerOnlyCodes,
        array $fullOnlyCodes,
        array $supplemental,
        array $optional,
    ): ?OracleDisagreement {
        if ($scenario->backendUnavailable) {
            return OracleDisagreement::BackendGap;
        }

        if ($compilerOnlyCodes !== [] || $fullOnlyCodes !== []) {
            if ($scenario->expectedDisagreement === OracleDisagreement::LanguagePolicyDifference) {
                return OracleDisagreement::LanguagePolicyDifference;
            }

            if ($compilerOnlyCodes === []) {
                return OracleDisagreement::CompilerGap;
            }

            if ($fullOnlyCodes === []) {
                return OracleDisagreement::BackendGap;
            }

            return OracleDisagreement::FixtureError;
        }

        if ($supplemental !== []) {
            return OracleDisagreement::Supplemental;
        }

        if ($optional !== []) {
            return OracleDisagreement::OptionalLint;
        }

        return null;
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
