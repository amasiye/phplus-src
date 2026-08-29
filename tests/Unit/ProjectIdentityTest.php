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

    expect($composer['name'])->toBe('amasiye/ppphp-src')
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
