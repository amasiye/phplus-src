<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseNotesValidator;

test('release notes accept concise user-facing content', function (): void {
    $notes = sprintf(
        "++PHP %s produces ordinary PHP 8.4.\n\ncomposer require --dev atatusoft-ltd/ppphp-src:%s\n",
        Compiler::VERSION,
        Compiler::VERSION,
    );

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))->toBe([]);
});

test('release notes reject internal implementation language', function (string $term): void {
    $notes = sprintf(
        "++PHP %s produces ordinary PHP 8.4.\n\ncomposer require --dev atatusoft-ltd/ppphp-src:%s\n\n%s\n",
        Compiler::VERSION,
        Compiler::VERSION,
        $term,
    );

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))
        ->toContain('release notes contain internal implementation language: ' . $term);
})->with([
    'Stage ',
    'MVP',
    'post-MVP',
    'compiler-owned',
    'compilerCore',
    'PHPStan',
    'parity',
    'promotion-readiness',
    'completion gate',
]);
