<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Amasiye\Ppphp\Analysis\Capability\CompilerCoverage;
use Amasiye\Ppphp\Analysis\Parity\AnalyzerParityFixtureRepository;
use Amasiye\Ppphp\Diagnostics\DiagnosticCatalog;

test('the analyzer capability catalog is unique ordered evidenced and diagnostic-backed', function (): void {
    $root = dirname(__DIR__, 3);
    $catalog = new AnalysisCapabilityCatalog();
    $capabilities = $catalog->all();
    $ids = array_map(static fn ($capability): string => $capability->id, $capabilities);
    $sorted = $ids;
    sort($sorted, SORT_STRING);
    $scenarios = (new AnalyzerParityFixtureRepository())->load($root . '/tests/Fixtures/AnalyzerParity/scenarios.php');
    $scenarioIds = array_map(static fn ($scenario): string => $scenario->id, $scenarios);

    expect(AnalysisCapabilityCatalog::VERSION)->toBe(1)
        ->and($ids)->toBe($sorted)
        ->and(array_unique($ids))->toHaveCount(count($ids))
        ->and(array_unique($scenarioIds))->toHaveCount(count($scenarioIds))
        ->and($capabilities)->toHaveCount(33)
        ->and($scenarios)->toHaveCount(33);

    foreach ($capabilities as $capability) {
        foreach ($capability->diagnosticCodes as $code) {
            expect(DiagnosticCatalog::definition($code)->code)->toBe($code);
        }

        if ($capability->compilerCoverage !== CompilerCoverage::BackendOnly) {
            expect($capability->fixtureEvidence)->not->toBeEmpty();
        }

        foreach ($capability->fixtureEvidence as $fixtureId) {
            expect($scenarioIds)->toContain($fixtureId);
        }
    }
});

test('the analyzer capability documentation is generated from the typed catalog', function (): void {
    $root = dirname(__DIR__, 3);
    $contents = file_get_contents($root . '/docs/analyzer-capabilities.md');

    expect($contents)->toBeString();

    preg_match(
        '/<!-- capability-catalog:start -->\n(?<table>.*?)\n<!-- capability-catalog:end -->/s',
        $contents,
        $matches,
    );

    expect($matches['table'] ?? null)->toBe((new AnalysisCapabilityCatalog())->renderMarkdownTable());
});

test('the committed analyzer parity golden uses the current stable catalog version', function (): void {
    $root = dirname(__DIR__, 3);
    $golden = json_decode(
        (string) file_get_contents($root . '/tests/Golden/Analysis/analyzer-parity.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($golden['catalogVersion'])->toBe(AnalysisCapabilityCatalog::VERSION)
        ->and($golden['capabilityCount'])->toBe(33)
        ->and($golden['scenarioCount'])->toBe(33);
});
