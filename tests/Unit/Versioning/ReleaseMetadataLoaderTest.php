<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;

test('committed release metadata has one exact immutable RC identity', function (): void {
    $root = dirname(__DIR__, 3);
    $metadata = (new ReleaseMetadataLoader($root))->load();

    expect($metadata)->not->toBeNull()
        ->and($metadata?->version->canonical)->toBe(Compiler::VERSION)
        ->and($metadata?->tag)->toBe(Compiler::VERSION)
        ->and($metadata?->channel->value)->toBe('rc')
        ->and($metadata?->prerelease)->toBeTrue()
        ->and($metadata?->schemaAsset)->toBe('ppphp.schema.json')
        ->and($metadata?->schemaUrl)->toBe('https://github.com/atatusoft-ltd/ppphp-src/releases/download/2026.3.1-rc-1/ppphp.schema.json');
});

test('missing development release metadata is an explicit non-release state', function (): void {
    $root = dirname(__DIR__, 3);
    $missing = $this->createTemporaryDirectory() . '/missing.json';

    expect((new ReleaseMetadataLoader($root, $missing))->load())->toBeNull();
});

test('present malformed release metadata fails closed', function (): void {
    $root = dirname(__DIR__, 3);
    $manifest = $this->createTemporaryDirectory() . '/manifest.json';
    $this->writeFile($manifest, "{}\n");

    expect(fn () => (new ReleaseMetadataLoader($root, $manifest))->load())
        ->toThrow(RuntimeException::class, 'release manifest is invalid');
});
