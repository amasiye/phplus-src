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

    $start = strpos($plan, '## Stage 15 — Native Type Ergonomics And Declarative Framework Metadata');
    $end = strpos($plan, '## 14. Dependency Policy', is_int($start) ? $start : 0);

    expect($start)->toBeInt()
        ->and($end)->toBeInt();

    if (!is_int($start) || !is_int($end)) {
        throw new RuntimeException('The bounded Stage 15 roadmap section is missing.');
    }

    $stage = substr($plan, $start, $end - $start);

    expect($stage)->toContain(
        'Stage 15A — Postfix List Types',
        'Stage 15B — Native Type Members',
        'Stage 15C — Deferred Attribute Factory Expressions',
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
