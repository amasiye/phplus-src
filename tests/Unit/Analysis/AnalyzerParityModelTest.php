<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Parity\OracleDisagreement;

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
