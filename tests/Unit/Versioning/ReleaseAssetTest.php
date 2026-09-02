<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Versioning\ReleaseAssetBuilder;
use Atatusoft\Ppphp\Versioning\ReleaseAssetVerifier;

test('release assets are exact reproducible and self-verifying', function (): void {
    $root = dirname(__DIR__, 3);
    $temporary = $this->createTemporaryDirectory();
    $first = $temporary . '/first';
    $second = $temporary . '/second';
    $commit = str_repeat('a', 40);
    $builder = new ReleaseAssetBuilder($root);
    $builder->build($first, $commit);
    $builder->build($second, $commit);
    $verifier = new ReleaseAssetVerifier($root);
    $verifier->verify($first, $commit);
    $verifier->verify($second, $commit);

    foreach (ReleaseAssetBuilder::ASSET_NAMES as $asset) {
        expect(file_get_contents($first . '/' . $asset))->toBe(file_get_contents($second . '/' . $asset));
    }
});

test('release asset verification detects tampering and unexpected files', function (): void {
    $root = dirname(__DIR__, 3);
    $output = $this->createTemporaryDirectory() . '/assets';
    $commit = str_repeat('b', 40);
    (new ReleaseAssetBuilder($root))->build($output, $commit);
    $this->writeFile($output . '/RELEASE_NOTES.md', "tampered\n");

    expect(fn () => (new ReleaseAssetVerifier($root))->verify($output, $commit))
        ->toThrow(UnexpectedValueException::class, 'does not match SHA256SUMS');
});

test('release asset builder rejects invalid commits and protected output roots', function (): void {
    $root = dirname(__DIR__, 3);
    $builder = new ReleaseAssetBuilder($root);

    expect(fn () => $builder->build($this->createTemporaryDirectory() . '/assets', 'HEAD'))
        ->toThrow(InvalidArgumentException::class, '40 lowercase hexadecimal')
        ->and(fn () => $builder->build($root . '/src/release-assets', str_repeat('c', 40)))
        ->toThrow(InvalidArgumentException::class, 'overlaps protected');
});
