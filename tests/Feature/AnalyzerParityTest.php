<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Parity\AnalyzerParityRunner;

test('the differential analyzer corpus matches its deterministic reviewed golden', function (): void {
    $root = dirname(__DIR__, 2);
    $report = (new AnalyzerParityRunner())->run($root . '/tests/Fixtures/AnalyzerParity/scenarios.php');
    $json = $report->toJson();
    $golden = file_get_contents($root . '/tests/Golden/Analysis/analyzer-parity.json');
    $disagreements = array_values(array_unique(array_filter(array_column(
        $report->payload['scenarios'],
        'observedDisagreement',
    ))));
    sort($disagreements, SORT_STRING);

    expect($report->hasUnexpectedResults)->toBeFalse()
        ->and($json)->toBe($golden)
        ->and($json)->not->toContain('/private/', '/tmp/', 'ppphp-analyzer-parity-', '"timestamp"', '"pid"', '"duration"')
        ->and($report->payload['requiredGaps'])->toBe(['interop.builtin-signatures', 'interop.composer-vendor'])
        ->and($report->payload['supplementalDifferenceCount'])->toBe(4)
        ->and($report->payload['optionalDifferenceCount'])->toBe(8)
        ->and($disagreements)->toBe(['backendGap', 'optionalLint', 'supplemental']);
});
