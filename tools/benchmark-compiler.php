#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\CompilerProjectAnalyzer;
use Amasiye\Ppphp\Cache\CacheStatistics;
use Amasiye\Ppphp\Cache\CompilerCache;
use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Compiler\CompilationResult;
use Amasiye\Ppphp\Compiler\Output\AtomicBuildCommitter;
use Amasiye\Ppphp\Compiler\Output\OutputPlanner;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectChecker;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelection;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Support\CanonicalJson;
use Amasiye\Ppphp\Transpilation\Emission\ProductionPhpEmitter;

require dirname(__DIR__) . '/vendor/autoload.php';

$format = 'markdown';
$outputPath = null;
$markdownOutputPath = null;
$iterations = 1;
$profile = 'full';

for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];

    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, 9);
    } elseif (str_starts_with($argument, '--output=')) {
        $outputPath = substr($argument, 9);
    } elseif (str_starts_with($argument, '--markdown-output=')) {
        $markdownOutputPath = substr($argument, 18);
    } elseif (str_starts_with($argument, '--iterations=')) {
        $iterations = filter_var(substr($argument, 13), FILTER_VALIDATE_INT);
    } elseif (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, 10);
    } else {
        fwrite(STDERR, sprintf("Unknown benchmark option: %s\n", $argument));
        exit(2);
    }
}

if (!in_array($format, ['json', 'markdown'], true)
    || !is_int($iterations)
    || $iterations < 1
    || $iterations > 20
    || !in_array($profile, ['smoke', 'full'], true)
    || ($outputPath !== null && $outputPath === '')
    || ($markdownOutputPath !== null && $markdownOutputPath === '')) {
    fwrite(STDERR, "Use --format=json|markdown, --iterations=1..20, --profile=smoke|full, and optional non-empty --output and --markdown-output paths.\n");
    exit(2);
}

$sizes = $profile === 'smoke' ? ['small' => 6] : ['small' => 6, 'medium' => 40, 'large' => 240];
$measurements = [];
$fixtureSizes = [];

foreach ($sizes as $sizeName => $sourceCount) {
    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $root = sys_get_temp_dir() . '/ppphp-benchmark-' . bin2hex(random_bytes(8));

        try {
            createBenchmarkProject($root, $sourceCount);
            [$project, $selection] = loadBenchmarkProject($root);
            $fixtureSizes[$sizeName] = sourceMetrics($project);

            $coreCache = new CompilerCache();
            $coreSnapshot = $coreCache->snapshot($project, $selection->analysisSources);
            $coreAnalysis = measureBenchmark(
                $measurements,
                $sizeName,
                'cold-compiler-core-check',
                $root,
                $project,
                $coreCache->statistics,
                function () use ($project, $selection) {
                    return (new CompilerProjectAnalyzer())->analyze($project, $selection->analysisSources);
                },
            );
            $coreCache->storeCompilerAnalysis($project, $coreSnapshot, $coreAnalysis);
            measureBenchmark(
                $measurements,
                $sizeName,
                'warm-compiler-core-check',
                $root,
                $project,
                $coreCache->statistics,
                fn () => $coreCache->loadCompilerCheck($project, $selection->analysisSources, $coreSnapshot)
                    ?? throw new RuntimeException('The benchmark compiler-core evidence could not be replayed.'),
            );

            removeBenchmarkPath($root . '/.ppphp-cache');
            $fullCache = new CompilerCache();
            $checker = new ProjectChecker(cache: $fullCache);
            measureBenchmark($measurements, $sizeName, 'cold-full-check', $root, $project, $fullCache->statistics, fn () => $checker->check($project, $selection->analysisSources));
            measureBenchmark($measurements, $sizeName, 'warm-full-check', $root, $project, $fullCache->statistics, fn () => $checker->check($project, $selection->analysisSources));

            removeBenchmarkPath($root . '/.ppphp-cache');
            $buildCache = new CompilerCache();
            $compiler = benchmarkCompiler($buildCache);
            measureBenchmark($measurements, $sizeName, 'cold-pathless-build', $root, $project, $buildCache->statistics, fn () => $compiler->compile($project, $selection));
            measureBenchmark($measurements, $sizeName, 'warm-pathless-build', $root, $project, $buildCache->statistics, fn () => $compiler->compile($project, $selection));

            writeBenchmarkFile($root . '/src/Module001.ppphp', "<?php\nfunction benchmarkModule001(): int { return 1001; }\n");
            [$bodyProject, $bodySelection] = loadBenchmarkProject($root);
            measureBenchmark($measurements, $sizeName, 'implementation-body-edit', $root, $bodyProject, $buildCache->statistics, fn () => $compiler->compile($bodyProject, $bodySelection));

            writeBenchmarkFile($root . '/src/Module001.ppphp', "<?php\nfunction benchmarkModule001(): string { return '1001'; }\n");
            [$declarationProject, $declarationSelection] = loadBenchmarkProject($root);
            measureBenchmark($measurements, $sizeName, 'public-declaration-edit', $root, $declarationProject, $buildCache->statistics, fn () => $compiler->compile($declarationProject, $declarationSelection));

            $focused = (new ProjectSelector())->select($declarationProject, 'src/Module001.ppphp', SelectionMode::Check)->selection;

            if ($focused === null) {
                throw new RuntimeException('The benchmark focused-check selection failed.');
            }

            measureBenchmark($measurements, $sizeName, 'focused-file-check', $root, $declarationProject, $buildCache->statistics, fn () => (new ProjectChecker(cache: $buildCache))->check($declarationProject, $focused->analysisSources));
            $focusedBuild = (new ProjectSelector())->select($declarationProject, 'src/Module001.ppphp', SelectionMode::Build)->selection;

            if ($focusedBuild === null) {
                throw new RuntimeException('The benchmark focused-build selection failed.');
            }

            measureBenchmark($measurements, $sizeName, 'focused-file-build', $root, $declarationProject, $buildCache->statistics, fn () => $compiler->compile($declarationProject, $focusedBuild));
        } finally {
            removeBenchmarkPath($root);
        }
    }
}

$projects = [];

foreach ($sizes as $sizeName => $_) {
    $scenarios = [];

    foreach ($measurements[$sizeName] ?? [] as $label => $samples) {
        $scenarios[] = medianBenchmarkSamples($label, $samples);
    }

    $projects[] = ['size' => $sizeName, ...$fixtureSizes[$sizeName], 'scenarios' => $scenarios];
}

$report = [
    'formatVersion' => 1,
    'methodology' => 'Median in-process measurements over deterministic generated projects; wall-clock values are informative, not CI thresholds.',
    'environment' => [
        'compilerVersion' => Compiler::VERSION,
        'osFamily' => PHP_OS_FAMILY,
        'phpVersion' => PHP_VERSION,
    ],
    'profile' => $profile,
    'iterations' => $iterations,
    'projects' => $projects,
];
$jsonReport = CanonicalJson::encode($report);
$markdownReport = renderBenchmarkMarkdown($report);
validateBenchmarkReport($report, $jsonReport, $markdownReport, array_keys($sizes));
$rendered = $format === 'json' ? $jsonReport : $markdownReport;

if ($outputPath === null) {
    fwrite(STDOUT, $rendered);
} else {
    writeBenchmarkReport($outputPath, $rendered);
}

if ($markdownOutputPath !== null) {
    writeBenchmarkReport($markdownOutputPath, $markdownReport);
}

/** @return array{Project, ProjectSelection} */
function loadBenchmarkProject(string $root): array
{
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;

    if ($configuration === null) {
        throw new RuntimeException('The generated benchmark configuration is invalid.');
    }

    $project = (new ProjectLoader())->load($configuration)->project;
    $selection = $project === null ? null : (new ProjectSelector())->select($project, null, SelectionMode::Build)->selection;

    if ($project === null || $selection === null) {
        throw new RuntimeException('The generated benchmark project could not be loaded.');
    }

    return [$project, $selection];
}

function benchmarkCompiler(CompilerCache $cache): Compiler
{
    return new Compiler(
        new ProjectChecker(cache: $cache),
        new OutputPlanner(),
        new ProductionPhpEmitter(),
        new AtomicBuildCommitter(),
        new ComposerRuntimeConfigurator(),
        cache: $cache,
    );
}

function createBenchmarkProject(string $root, int $sourceCount): void
{
    writeBenchmarkFile($root . '/ppphp.json', json_encode([
        'source' => ['src'],
        'output' => 'build/ppphp',
        'cache' => '.ppphp-cache',
        'targetPhpVersion' => '8.4',
        'stubs' => ['stubs'],
        'exclude' => ['vendor', 'build', '.ppphp-cache'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    writeBenchmarkFile($root . '/composer.json', json_encode([
        'require' => ['acme/benchmark' => '1.0.0'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    writeBenchmarkFile($root . '/composer.lock', json_encode([
        'content-hash' => 'ppphp-stage-13d-benchmark',
        'packages' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    writeBenchmarkFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/benchmark',
            'version' => '1.0.0.0',
            'pretty_version' => '1.0.0',
            'type' => 'library',
            'install_path' => '../acme/benchmark',
            'autoload' => ['files' => ['functions.php']],
            'require' => ['php' => '^8.4'],
        ]],
        'dev' => true,
        'dev-package-names' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    writeBenchmarkFile(
        $root . '/vendor/acme/benchmark/functions.php',
        "<?php\nfunction benchmarkDependency(int \$value): int { return \$value; }\n",
    );
    writeBenchmarkFile($root . '/stubs/Benchmark.stub.php', "<?php\nfunction benchmarkExternal(string \$value): int {}\n");
    writeBenchmarkFile($root . '/src/Features.ppphp', <<<'PHP'
<?php
class BenchmarkBox<T> {
    public function __construct(public T $value) {}
    public function get(): T { return $this->value; }
}
function benchmarkValues(array<string, int> $values): array<string, int> { return $values; }
function benchmarkRisky(): void throws RuntimeException { throw new RuntimeException(); }
function benchmarkWhen(bool $condition): int {
    return when ($condition) { return 1; } else { return 0; };
}
function benchmarkDependencyValue(): int { return benchmarkDependency(1); }
PHP
    );

    for ($index = 1; $index < $sourceCount; $index++) {
        $name = sprintf('Module%03d', $index);
        $function = sprintf('benchmarkModule%03d', $index);
        $extension = $index % 5 === 0 ? 'php' : 'ppphp';
        writeBenchmarkFile(
            sprintf('%s/src/%s.%s', $root, $name, $extension),
            sprintf("<?php\nfunction %s(): int { return %d; }\n", $function, $index),
        );
    }
}

function writeBenchmarkFile(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('A benchmark fixture directory could not be created.');
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('A benchmark fixture file could not be written.');
    }
}

function writeBenchmarkReport(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "The benchmark output directory could not be created.\n");
        exit(1);
    }

    if (file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "The benchmark report could not be written.\n");
        exit(1);
    }
}

/** @return array{sourceBytes: int, sourceFileCount: int} */
function sourceMetrics(Project $project): array
{
    $bytes = 0;

    foreach ($project->sources as $source) {
        $size = filesize($source->path);
        $bytes += is_int($size) ? $size : 0;
    }

    return ['sourceBytes' => $bytes, 'sourceFileCount' => count($project->sources)];
}

/** @param array<string, array<string, list<array<string, int|float|string|bool>>>> $measurements */
function measureBenchmark(
    array &$measurements,
    string $size,
    string $label,
    string $root,
    Project $project,
    ?CacheStatistics $statistics,
    callable $operation,
): mixed {
    $before = $statistics?->toArray() ?? [];

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $started = hrtime(true);
    $result = $operation();
    $duration = (hrtime(true) - $started) / 1_000_000;
    $after = $statistics?->toArray() ?? [];
    $delta = [];

    foreach ($after as $name => $value) {
        $delta[$name] = $value - ($before[$name] ?? 0);
    }

    $successful = str_contains($label, 'compiler-core') && property_exists($result, 'compilerEvidence')
        ? $result->compilerEvidence && !$result->diagnostics->hasErrors
        : (property_exists($result, 'isSuccessful') ? $result->isSuccessful : false);

    if (!$successful) {
        $details = property_exists($result, 'diagnostics')
            ? implode('; ', array_map(
                static fn ($diagnostic): string => $diagnostic->code->value . ' ' . $diagnostic->message,
                iterator_to_array($result->diagnostics),
            ))
            : '';
        throw new RuntimeException(sprintf(
            'Benchmark scenario "%s" failed%s.',
            $label,
            $details === '' ? '' : ': ' . $details,
        ));
    }

    $sourceCount = count($project->sources);
    $isCompilation = $result instanceof CompilationResult;
    $artifactCount = $isCompilation ? count($result->artifacts) : 0;
    $usesSupplemental = !str_contains($label, 'compiler-core')
        && (str_contains($label, 'full')
            || str_contains($label, 'build')
            || str_contains($label, 'edit')
            || str_contains($label, 'focused'));
    $output = treeMetrics($root . '/build/ppphp');
    $measurements[$size][$label][] = [
        'cacheBytes' => treeMetrics($root . '/.ppphp-cache')['bytes'],
        'cacheHits' => $delta['hits'] ?? 0,
        'cacheMisses' => $delta['misses'] ?? 0,
        'durationMilliseconds' => round($duration, 3),
        'filesLowered' => max(0, $artifactCount - ($delta['loweringWorkAvoided'] ?? 0)),
        'filesNormalized' => max(0, $sourceCount - ($delta['parserWorkAvoided'] ?? 0)),
        'filesReparsed' => max(0, $sourceCount - ($delta['parserWorkAvoided'] ?? 0)),
        'filesTokenized' => max(0, $sourceCount - ($delta['parserWorkAvoided'] ?? 0)),
        'outputBytes' => $output['bytes'],
        'outputFileCount' => $output['files'],
        'peakMemoryBytes' => memory_get_peak_usage(true),
        'phpLintProcessLaunches' => $isCompilation && !$result->upToDate ? $artifactCount : 0,
        'phpStanProcessLaunches' => $usesSupplemental && ($delta['supplementalProcessesAvoided'] ?? 0) === 0 ? 1 : 0,
        'semanticAnalyses' => ($delta['semanticWorkAvoided'] ?? 0) > 0 ? 0 : 1,
    ];

    return $result;
}

/** @return array{bytes: int, files: int} */
function treeMetrics(string $root): array
{
    if (!is_dir($root) || is_link($root)) {
        return ['bytes' => 0, 'files' => 0];
    }

    $bytes = 0;
    $files = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink()) {
            $bytes += max(0, $file->getSize());
            $files++;
        }
    }

    return ['bytes' => $bytes, 'files' => $files];
}

/** @param list<array<string, int|float|string|bool>> $samples */
function medianBenchmarkSamples(string $label, array $samples): array
{
    $result = ['label' => $label];

    foreach (array_keys($samples[0]) as $field) {
        $values = array_column($samples, $field);
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $result[$field] = count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    return $result;
}

function renderBenchmarkMarkdown(array $report): string
{
    $lines = ['# ++PHP Compiler Benchmark', '', $report['methodology'], ''];

    foreach ($report['projects'] as $project) {
        $lines[] = sprintf('## %s (%d files, %d bytes)', ucfirst($project['size']), $project['sourceFileCount'], $project['sourceBytes']);
        $lines[] = '';
        $lines[] = '| Scenario | Median ms | Peak memory | Cache hits/misses | Tokenized | Lowered | PHPStan | Lint |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |';

        foreach ($project['scenarios'] as $scenario) {
            $lines[] = sprintf(
                '| `%s` | %.3f | %d | %d/%d | %d | %d | %d | %d |',
                $scenario['label'],
                $scenario['durationMilliseconds'],
                $scenario['peakMemoryBytes'],
                $scenario['cacheHits'],
                $scenario['cacheMisses'],
                $scenario['filesTokenized'],
                $scenario['filesLowered'],
                $scenario['phpStanProcessLaunches'],
                $scenario['phpLintProcessLaunches'],
            );
        }

        $lines[] = '';
    }

    return implode("\n", $lines);
}

/** @param list<string> $expectedSizes */
function validateBenchmarkReport(array $report, string $json, string $markdown, array $expectedSizes): void
{
    $requiredLabels = [
        'cold-compiler-core-check',
        'warm-compiler-core-check',
        'cold-full-check',
        'warm-full-check',
        'cold-pathless-build',
        'warm-pathless-build',
        'implementation-body-edit',
        'public-declaration-edit',
        'focused-file-check',
        'focused-file-build',
    ];
    $metricFields = [
        'cacheBytes',
        'cacheHits',
        'cacheMisses',
        'durationMilliseconds',
        'filesLowered',
        'filesNormalized',
        'filesReparsed',
        'filesTokenized',
        'outputBytes',
        'outputFileCount',
        'peakMemoryBytes',
        'phpLintProcessLaunches',
        'phpStanProcessLaunches',
        'semanticAnalyses',
    ];

    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

    if (($report['formatVersion'] ?? null) !== 1
        || !is_array($report['projects'] ?? null)
        || array_column($report['projects'], 'size') !== $expectedSizes
        || !is_array($decoded)
        || CanonicalJson::encode($decoded) !== $json
        || !str_starts_with($markdown, '# ++PHP Compiler Benchmark')) {
        throw new RuntimeException('The benchmark report envelope is invalid.');
    }

    foreach ($report['projects'] as $project) {
        $sourceCount = $project['sourceFileCount'] ?? null;
        $scenarios = $project['scenarios'] ?? null;

        if (!is_int($sourceCount)
            || $sourceCount < 1
            || !is_int($project['sourceBytes'] ?? null)
            || !is_array($scenarios)
            || array_column($scenarios, 'label') !== $requiredLabels) {
            throw new RuntimeException('A benchmark project or scenario set is invalid.');
        }

        foreach ($scenarios as $scenario) {
            foreach ($metricFields as $field) {
                if (!is_int($scenario[$field] ?? null) && !is_float($scenario[$field] ?? null)) {
                    throw new RuntimeException(sprintf('Benchmark metric "%s" is unavailable.', $field));
                }

                if ($scenario[$field] < 0) {
                    throw new RuntimeException(sprintf('Benchmark metric "%s" is negative.', $field));
                }
            }

            foreach (['filesLowered', 'filesNormalized', 'filesReparsed', 'filesTokenized'] as $field) {
                if ($scenario[$field] > $sourceCount) {
                    throw new RuntimeException(sprintf('Benchmark counter "%s" exceeds the project size.', $field));
                }
            }
        }

        $byLabel = array_column($scenarios, null, 'label');

        foreach (['warm-compiler-core-check', 'warm-full-check', 'warm-pathless-build'] as $label) {
            if (($byLabel[$label]['filesTokenized'] ?? 1) !== 0
                || ($byLabel[$label]['semanticAnalyses'] ?? 1) !== 0
                || ($byLabel[$label]['phpStanProcessLaunches'] ?? 1) !== 0) {
                throw new RuntimeException(sprintf('Benchmark scenario "%s" did not prove warm reuse.', $label));
            }
        }

        if (($byLabel['warm-pathless-build']['filesLowered'] ?? 1) !== 0
            || ($byLabel['warm-pathless-build']['phpLintProcessLaunches'] ?? 1) !== 0
            || ($byLabel['implementation-body-edit']['filesLowered'] ?? $sourceCount) >= $sourceCount) {
            throw new RuntimeException('Benchmark build reuse counters are inconsistent.');
        }
    }

    foreach ([$json, $markdown] as $rendered) {
        if (str_contains($rendered, sys_get_temp_dir() . '/ppphp-benchmark-')
            || preg_match('/(?:PASSWORD|TOKEN|SECRET|PRIVATE_KEY)=/i', $rendered) === 1) {
            throw new RuntimeException('The benchmark report exposes transient paths or environment secrets.');
        }
    }
}

function removeBenchmarkPath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            removeBenchmarkPath($entry->getPathname());
        }
    }

    @rmdir($path);
}
