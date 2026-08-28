<?php

declare(strict_types=1);

use Amasiye\Phplus\Diagnostics\ConsoleRenderer;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\DiagnosticLabel;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Diagnostics\JsonRenderer;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Source\SourceFile;

test('diagnostic bags retain deterministic order and expose severity queries', function (): void {
    $error = new Diagnostic(DiagnosticCode::InvalidInvocation, Severity::Error, 'Invalid Invocation', 'Bad input.');
    $warning = new Diagnostic(DiagnosticCode::InvalidInvocation, Severity::Warning, 'Warning', 'Careful.');
    $note = new Diagnostic(DiagnosticCode::InvalidInvocation, Severity::Note, 'Note', 'Context.');
    $bag = new DiagnosticBag();
    $bag->addAll([$warning, $error, $note]);

    expect($bag)->toHaveCount(3)
        ->and($bag->hasErrors)->toBeTrue()
        ->and($bag->errors)->toBe([$error])
        ->and($bag->warnings)->toBe([$warning])
        ->and($bag->notes)->toBe([$note])
        ->and(iterator_to_array($bag))->toBe([$warning, $error, $note]);
});

test('console diagnostics render source spans related labels help and multiline ranges', function (): void {
    $source = new SourceFile('/project/phplus.json', 'phplus.json', FileKind::Configuration, "{\n  \"bad\": true\n}\n");
    $primary = new DiagnosticLabel($source->createSpan(4, 9), 'This Property Is Not Supported');
    $related = new DiagnosticLabel($source->createSpan(0, 16), 'Configuration Object');
    $bag = new DiagnosticBag();
    $bag->add(new Diagnostic(
        DiagnosticCode::UnknownConfigurationProperty,
        Severity::Error,
        'Unknown Configuration Property',
        'The property "bad" is not supported.',
        $primary,
        [$related],
        'Remove "bad".',
    ));
    $rendered = (new ConsoleRenderer())->render($bag);

    expect($rendered)->toContain('Error[P0004]: Unknown Configuration Property')
        ->and($rendered)->toContain('phplus.json:2:3')
        ->and($rendered)->toContain('2 |   "bad": true')
        ->and($rendered)->toContain('^^^^^ This Property Is Not Supported')
        ->and($rendered)->toContain('Related: Configuration Object')
        ->and($rendered)->toContain('... through line 3')
        ->and($rendered)->toContain('Help: Remove "bad".')
        ->and($rendered)->not->toContain("\e[");
});

test('console diagnostics without source remain concise', function (): void {
    $bag = new DiagnosticBag();
    $bag->add(new Diagnostic(
        DiagnosticCode::CompilerFrontendNotAvailable,
        Severity::Error,
        'Compiler Frontend Is Not Available',
        'Source parsing is unavailable.',
    ));

    expect((new ConsoleRenderer())->render($bag))->toBe(
        "Error[P0010]: Compiler Frontend Is Not Available\n\nSource parsing is unavailable.\n",
    );
});

test('JSON diagnostics use the stable envelope and exact source ranges', function (): void {
    $source = new SourceFile('/project/main.php', 'main.php', FileKind::Php, 'abc');
    $bag = new DiagnosticBag();
    $bag->add(new Diagnostic(
        DiagnosticCode::InvalidInvocation,
        Severity::Error,
        'Invalid Invocation',
        'Bad input.',
        new DiagnosticLabel($source->createSpan(1, 3), 'Invalid bytes'),
    ));
    $json = (new JsonRenderer())->render($bag);
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['version'])->toBe(1)
        ->and($decoded['diagnostics'][0]['location']['range']['start'])->toBe([
            'offset' => 1,
            'line' => 1,
            'column' => 2,
        ])
        ->and($decoded['diagnostics'][0]['location']['range']['end']['offset'])->toBe(3)
        ->and($decoded['summary'])->toBe(['errors' => 1, 'warnings' => 0, 'notes' => 0])
        ->and($json)->not->toContain("\e[");
});

test('empty JSON diagnostics still produce a valid envelope', function (): void {
    $decoded = json_decode((new JsonRenderer())->render(new DiagnosticBag()), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        'version' => 1,
        'diagnostics' => [],
        'summary' => ['errors' => 0, 'warnings' => 0, 'notes' => 0],
    ]);
});
