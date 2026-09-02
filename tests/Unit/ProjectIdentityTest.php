<?php

declare(strict_types=1);

use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\PpphpParser;

test('the canonical project identity is complete', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $configuration = json_decode(
        (string) file_get_contents($root . '/ppphp.json.dist'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['name'])->toBe('atatusoft-ltd/ppphp-src')
        ->and($composer['autoload']['psr-4'])->toBe(['Amasiye\\Ppphp\\' => 'src/'])
        ->and($composer['bin'])->toBe(['bin/ppphp'])
        ->and(class_exists(PpphpParser::class))->toBeTrue()
        ->and(file_exists($root . '/resources/schema/ppphp.schema.json'))->toBeTrue()
        ->and(file_exists($root . '/resources/phpstan/ppphp.neon'))->toBeTrue()
        ->and(file_exists($root . '/docs/ppphp-mvp-end-to-end-plan.md'))->toBeTrue()
        ->and($configuration['cache'])->toBe('.ppphp-cache')
        ->and($configuration['output'])->toBe('build/ppphp');
});

test('the configuration loader does not fall back to the retired filename', function (): void {
    $root = $this->createTemporaryDirectory();
    $retiredFilename = 'ph' . 'plus.json';
    $this->writeFile($root . '/' . $retiredFilename, '{}');
    $result = (new ProjectConfigLoader())->load($root);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::ProjectConfigurationNotFound)
        ->and($result->diagnostics->errors[0]->message)->toContain('ppphp.json');
});

test('the post-MVP roadmap records the bounded Stage 15 contracts', function (): void {
    $plan = file_get_contents(dirname(__DIR__, 2) . '/docs/ppphp-mvp-end-to-end-plan.md');

    expect($plan)->not->toBeFalse();

    if (!is_string($plan)) {
        throw new RuntimeException('The end-to-end plan could not be read.');
    }

    $start = strpos($plan, '## Stage 15 — Immutable Records, Native Type Ergonomics, And Declarative Framework Metadata');
    $end = strpos($plan, '## 14. Dependency Policy', is_int($start) ? $start : 0);

    expect($start)->toBeInt()
        ->and($end)->toBeInt();

    if (!is_int($start) || !is_int($end)) {
        throw new RuntimeException('The bounded Stage 15 roadmap section is missing.');
    }

    $stage = substr($plan, $start, $end - $start);

    expect($stage)->toContain(
        'Stage 15A — Immutable Records',
        'Stage 15B — Postfix List Types',
        'Stage 15C — Native Type Members',
        'Stage 15D — Deferred Attribute Factory Expressions',
        'length',
        'toLower',
        'count',
        'first',
        'firstKey',
        'find',
        'findKey',
        'filter',
        'map',
        'reduce',
    );
});

test('the accepted Records RFC and roadmap preserve the complete Stage 15A contract', function (): void {
    $root = dirname(__DIR__, 2);
    $rfc = file_get_contents($root . '/docs/rfcs/0001-immutable-records.md');
    $plan = file_get_contents($root . '/docs/ppphp-mvp-end-to-end-plan.md');

    expect($rfc)->toBeString()
        ->and($plan)->toBeString();

    if (!is_string($rfc) || !is_string($plan)) {
        throw new RuntimeException('The Records documentation could not be read.');
    }

    foreach ([$rfc, $plan] as $document) {
        expect($document)->toContain(
            'contextual class-like declaration keyword',
            'implicitly final',
            'public readonly',
            'compiler-generated constructor',
            'generic',
            'implement interfaces',
            'virtual get-only computed properties',
            'set hooks',
            'additional backed instance state',
            'custom constructor',
            'extend a class',
            'shallow',
            'ordinary PHP object identity',
            'final class',
            'synthesized equality',
        );
    }
});

test('the Stage 13D documentation status and native analyzer contract agree', function (): void {
    $root = dirname(__DIR__, 2);
    $primary = [
        $root . '/AGENTS.md',
        $root . '/README.md',
        $root . '/docs/ppphp-mvp-end-to-end-plan.md',
        $root . '/docs/compiler-architecture.md',
    ];

    foreach ($primary as $path) {
        $document = file_get_contents($path);
        expect($document)->toBeString()
            ->and($document)->toContain('Stages 13A–13D', 'Stage 14 is next', 'dev-2026.3.1');
    }

    $analysis = (string) file_get_contents($root . '/docs/analyzer-independence.md');
    $phpStan = (string) file_get_contents($root . '/docs/phpstan-integration.md');

    expect($analysis)->toContain('product decision remains pending explicit approval')
        ->and($phpStan)->toContain('mandatory for normal native check/build')
        ->and($phpStan)->toContain('changing the default is pending explicit approval');

    foreach ([...$primary, $root . '/docs/analyzer-independence.md'] as $path) {
        $document = (string) file_get_contents($path);
        expect($document)->not->toMatch('/Stage 13D (?:is next|work has not started|should implement)/i');
    }
});
