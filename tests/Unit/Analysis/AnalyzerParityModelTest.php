<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Analysis\Parity\DiagnosticFingerprint;
use Atatusoft\Ppphp\Analysis\Parity\DiagnosticSetDiffer;
use Atatusoft\Ppphp\Analysis\Parity\OracleDisagreement;

test('oracle disagreement categories remain explicit and stable', function (): void {
    expect(array_map(static fn (OracleDisagreement $case): string => $case->value, OracleDisagreement::cases()))->toBe([
        'compilerGap',
        'backendGap',
        'languagePolicyDifference',
        'optionalLint',
        'supplemental',
        'fixtureError',
    ]);
});

test('required parity subtraction preserves diagnostic fingerprints and multiplicity', function (): void {
    $misplaced = new DiagnosticFingerprint('P2016', 'src/Feature.ppphp', 10, 15, 'return:first');
    $matching = new DiagnosticFingerprint('P2016', 'src/Feature.ppphp', 30, 35, 'return:second');
    $differ = new DiagnosticSetDiffer();

    expect($differ->subtract([$misplaced, $matching], [$matching]))->toBe([$misplaced])
        ->and($differ->subtract([$matching], [$misplaced]))->toBe([$matching])
        ->and($differ->subtract([$matching, $matching], [$matching]))->toBe([$matching]);
});
