<?php

declare(strict_types=1);

test('the project configuration template contains valid JSON', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $contents = file_get_contents($projectRoot . '/phplus.json.dist');

    expect($contents)->not->toBeFalse();

    $configuration = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($configuration)->toBeArray()
        ->and($configuration['source'])->toBe(['src'])
        ->and($configuration['targetPhpVersion'])->toBe('8.4');
});

test('the project configuration schema contains valid JSON', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $contents = file_get_contents($projectRoot . '/resources/schema/phplus.schema.json');

    expect($contents)->not->toBeFalse();

    $schema = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($schema)->toBeArray()
        ->and($schema['$schema'])->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toContain('source', 'output', 'cache', 'targetPhpVersion');
});
