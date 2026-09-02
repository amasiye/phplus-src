#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Atatusoft\Ppphp\Analysis\Capability\CapabilityRequirement;
use Atatusoft\Ppphp\Analysis\Capability\CompilerCoverage;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\CanonicalJson;

require dirname(__DIR__) . '/vendor/autoload.php';

$format = 'markdown';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, 9);
        continue;
    }

    fwrite(STDERR, sprintf("Unknown analyzer-promotion option: %s\n", $argument));
    exit(2);
}

if (!in_array($format, ['json', 'markdown'], true)) {
    fwrite(STDERR, "Analyzer-promotion format must be json or markdown.\n");
    exit(2);
}

$root = dirname(__DIR__);
$catalog = new AnalysisCapabilityCatalog();
$parityContents = file_get_contents($root . '/tests/Golden/Analysis/analyzer-parity.json');
$parity = is_string($parityContents)
    ? json_decode($parityContents, true, flags: JSON_THROW_ON_ERROR)
    : null;
$requiredComplete = array_filter(
    $catalog->all,
    static fn ($capability): bool => $capability->requirement !== CapabilityRequirement::Optional
        && $capability->compilerCoverage !== CompilerCoverage::Complete,
) === [];
$boundaryComplete = array_filter(
    $catalog->all,
    static fn ($capability): bool => $capability->requirement === CapabilityRequirement::Boundary
        && $capability->compilerCoverage !== CompilerCoverage::Complete,
) === [];
$parityClean = is_array($parity)
    && ($parity['fullParity'] ?? null) === true
    && ($parity['requiredGaps'] ?? null) === []
    && ($parity['unexpectedCompilerDiagnostics'] ?? null) === []
    && ($parity['unexpectedFullDiagnostics'] ?? null) === []
    && ($parity['expectationFailures'] ?? null) === [];

$gates = [
    ['id' => 'capabilities.mvp', 'pass' => $requiredComplete, 'evidence' => 'AnalysisCapabilityCatalog and composer verify:analyzer-parity'],
    ['id' => 'capabilities.boundary', 'pass' => $boundaryComplete, 'evidence' => 'AnalysisCapabilityCatalog boundary entries'],
    ['id' => 'parity.required', 'pass' => $parityClean, 'evidence' => 'tests/Golden/Analysis/analyzer-parity.json'],
    ['id' => 'corpus.false-negatives', 'pass' => $parityClean, 'evidence' => '72 analyzer parity scenarios'],
    ['id' => 'examples.false-positives', 'pass' => is_file($root . '/tests/Support/verify-mixed-application.php'), 'evidence' => 'composer verify:mixed-application'],
    ['id' => 'examples.shopping-cart', 'pass' => is_dir($root . '/tests/Fixtures/GenericContext/ShoppingCart'), 'evidence' => 'tests/Fixtures/GenericContext/ShoppingCart'],
    ['id' => 'boundaries.platform-dependencies', 'pass' => $boundaryComplete, 'evidence' => 'composer verify:php-signatures and composer verify:dependency-index'],
    ['id' => 'output.php-lint', 'pass' => is_file($root . '/tests/Unit/Compiler/StageTenPhpLintValidatorTest.php'), 'evidence' => 'StageTenPhpLintValidatorTest and production build tests'],
    ['id' => 'browser.protocol-v2', 'pass' => is_file($root . '/tests/Unit/Analysis/CompilerAnalysisProtocolTest.php'), 'evidence' => 'CompilerAnalysisProtocolTest and tools/web-spike'],
    ['id' => 'cache.corruption-safety', 'pass' => is_file($root . '/tests/Unit/Cache/CompilerCacheTest.php'), 'evidence' => 'CompilerCacheTest and composer verify:cache'],
    ['id' => 'hardening.malformed-input', 'pass' => is_file($root . '/tools/fuzz-smoke.php'), 'evidence' => 'composer verify:fuzz-smoke and DiagnosticsTest'],
    ['id' => 'hardening.transactions', 'pass' => is_file($root . '/src/Compiler/Output/BuildTransactionRecovery.php'), 'evidence' => 'StageTenCommandsTest and StageThirteenDTransactionTest'],
];
$technicalPass = array_filter($gates, static fn (array $gate): bool => !$gate['pass']) === [];
$report = [
    'formatVersion' => 1,
    'compiler' => ['name' => Compiler::NAME, 'version' => Compiler::VERSION],
    'catalogVersion' => AnalysisCapabilityCatalog::VERSION,
    'gates' => $gates,
    'technicalStatus' => $technicalPass ? 'pass' : 'fail',
    'readiness' => $technicalPass ? 'technically-ready' : 'not-ready',
    'mvpDecision' => 'retain-supplemental',
    'futureDefaultChange' => 'not-approved',
    'nativeDefault' => 'PHPStan supplemental path',
    'remainingConsiderations' => [
        'Optional deep ordinary-PHP body analysis',
        'Optional generator-specific flow',
        'Backend infrastructure findings',
    ],
    'decisionRecord' => 'docs/decisions/0004-mvp-native-analysis-retains-phpstan.md',
];

if ($format === 'json') {
    fwrite(STDOUT, CanonicalJson::encode($report));
} else {
    $lines = [
        '# Analyzer Promotion Readiness',
        '',
        sprintf('- Technical gates: **%s**', ucfirst($report['technicalStatus'])),
        '- MVP decision: **Retain supplemental analysis**',
        '- Future default change: **Not approved**',
        '- Native default: **PHPStan supplemental path**',
        sprintf('- Readiness: `%s`', $report['readiness']),
        '',
        '| Gate | Result | Executable evidence |',
        '| --- | --- | --- |',
    ];

    foreach ($gates as $gate) {
        $lines[] = sprintf('| `%s` | %s | %s |', $gate['id'], $gate['pass'] ? 'Pass' : 'Fail', $gate['evidence']);
    }

    array_push($lines, '', 'ADR 0004 retains PHPStan for the MVP native path. PHPStan remains installed and required. Optional deep ordinary-PHP body analysis, generator flow, and backend infrastructure findings remain visible considerations. A future default change requires a separate post-MVP decision.', '');
    fwrite(STDOUT, implode("\n", $lines));
}

exit($technicalPass ? 0 : 1);
