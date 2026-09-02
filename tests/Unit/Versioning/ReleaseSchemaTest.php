<?php

declare(strict_types=1);

use Amasiye\Ppphp\Versioning\ReleaseSchema;
use Amasiye\Ppphp\Versioning\ReleaseVersion;

test('schema artifacts use the exact immutable identity of every release channel', function (string $version): void {
    $schema = new ReleaseSchema(ReleaseVersion::parse($version));
    $expected = sprintf(
        'https://github.com/atatusoft-ltd/ppphp-src/releases/download/%s/ppphp.schema.json',
        $version,
    );

    expect($schema->tag)->toBe($version)
        ->and($schema->url)->toBe($expected)
        ->and(fn () => $schema->validatePublishedIdentity($version, $expected))->not->toThrow(Throwable::class);
})->with([
    'stable' => ['2026.3.1'],
    'release candidate' => ['2026.3.1-rc-2'],
    'development' => ['dev-2026.3.1'],
]);

test('schema publication rejects mutable URLs and mismatched tags', function (): void {
    $schema = new ReleaseSchema(ReleaseVersion::parse('dev-2026.3.1'));

    expect(fn () => $schema->validatePublishedIdentity(
        'dev-2026.3.1',
        'https://raw.githubusercontent.com/atatusoft-ltd/ppphp-src/develop/resources/schema/ppphp.schema.json',
    ))->toThrow(InvalidArgumentException::class, 'exact immutable release URL')
        ->and(fn () => $schema->validatePublishedIdentity('2026.3.1', $schema->url))
        ->toThrow(InvalidArgumentException::class, 'tag does not match');
});

test('untagged development configuration omits a schema URL', function (): void {
    $template = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/ppphp.json.dist'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($template)->not->toHaveKey('$schema');
});
