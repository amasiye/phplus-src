<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Parity\DiagnosticFingerprint;
use Amasiye\Ppphp\Analysis\Parity\DiagnosticSetDiffer;
use Amasiye\Ppphp\Analysis\Parity\OracleDisagreement;

test('diagnostic differential matching preserves duplicate counts ranges and identities', function (): void {
    $first = new DiagnosticFingerprint('P2015', 'src/main.ppphp', 10, 20, 'argument.type');
    $duplicate = new DiagnosticFingerprint('P2015', 'src/main.ppphp', 10, 20, 'argument.type');
    $differentIdentity = new DiagnosticFingerprint('P2015', 'src/main.ppphp', 10, 20, 'argument.byRef');
    $differentRange = new DiagnosticFingerprint('P2015', 'src/main.ppphp', 30, 40, 'argument.type');
    $difference = (new DiagnosticSetDiffer())->subtract(
        [$first, $duplicate, $differentIdentity, $differentRange],
        [$first],
    );

    expect($difference)->toHaveCount(3)
        ->and(array_map(static fn (DiagnosticFingerprint $item): string => $item->key, $difference))->toBe([
            $duplicate->key,
            $differentIdentity->key,
            $differentRange->key,
        ]);
});

test('oracle disagreement categories remain explicit and stable', function (): void {
    expect(array_map(static fn (OracleDisagreement $case): string => $case->value, OracleDisagreement::cases()))->toBe([
        'compilerGap',
        'backendGap',
        'languagePolicyDifference',
        'optionalLint',
        'fixtureError',
    ]);
});
