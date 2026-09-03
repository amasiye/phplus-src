<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseNotesValidator;

function validReleaseNotes(): string
{
    return sprintf(
        <<<'MARKDOWN'
# ++PHP %1$s

++PHP %1$s is a release candidate that produces ordinary PHP 8.4. Behavior may change before the first Stable release.

composer require --dev atatusoft-ltd/ppphp-src:%1$s

## Requirements

- PHP `^8.4`
- Composer 2
- At least 512 MiB of memory available to compiler processes

## Major Features

- Typed local bindings.

The canonical compiler namespace is `Atatusoft\Ppphp`.

## Known Limitations

- Records are future work and are not part of this release.
MARKDOWN,
        Compiler::VERSION,
    );
}

test('release notes accept concise user-facing release candidate content', function (): void {
    expect((new ReleaseNotesValidator())->validate(validReleaseNotes(), Compiler::VERSION, '8.4'))->toBe([]);
});

test('release notes reject internal development process language', function (string $term): void {
    $notes = validReleaseNotes() . "\n" . $term . "\n";
    $failures = (new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4');

    expect(implode("\n", $failures))->toContain('prohibited public process language');
})->with([
    'Stage 13D completed the cache.',
    'The completion gate passed.',
]);

test('release notes allow user-relevant supplemental analysis disclosure', function (): void {
    $notes = validReleaseNotes() . "\nNative checks include supplemental PHPStan analysis.\n";

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))->toBe([]);
});

test('release notes require compiler prerequisites', function (string $requirement, string $message): void {
    $notes = str_replace($requirement, '', validReleaseNotes());

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))
        ->toContain($message);
})->with([
    ['## Requirements', 'release notes do not contain a requirements section'],
    ['PHP `^8.4`', 'release notes do not state the compiler PHP requirement'],
    ['Composer 2', 'release notes do not state the Composer requirement'],
    ['512 MiB', 'release notes do not state the compiler memory requirement'],
]);

test('release notes reject contradictory Stable claims', function (string $claim): void {
    $notes = validReleaseNotes() . "\n" . $claim . "\n";

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))
        ->toContain('release notes incorrectly claim Stable release status');
})->with([
    'This version is the Stable release.',
    'The candidate is now Stable.',
    'Stable is now available.',
    'Published as Stable.',
]);

test('the maintained release notes satisfy the release contract', function (): void {
    $notes = file_get_contents(dirname(__DIR__, 3) . '/docs/releases/' . Compiler::VERSION . '.md');

    expect($notes)->toBeString();

    if (!is_string($notes)) {
        throw new RuntimeException('The maintained release notes could not be read.');
    }

    expect((new ReleaseNotesValidator())->validate($notes, Compiler::VERSION, '8.4'))->toBe([])
        ->and($notes)->toContain(
            'release candidate',
            '## Requirements',
            'PHP `^8.4`',
            'Composer 2',
            '512 MiB',
            '## Major Features',
            '## Known Limitations',
        )
        ->and($notes)->not->toContain('Stable is now available');
});
