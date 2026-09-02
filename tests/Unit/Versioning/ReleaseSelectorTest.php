<?php

declare(strict_types=1);

use Amasiye\Ppphp\Versioning\ReleaseSelector;
use Amasiye\Ppphp\Versioning\ReleaseVersion;

/** @return list<ReleaseVersion> */
function quarterlyReleaseCatalog(): array
{
    return array_map(
        ReleaseVersion::parse(...),
        [
            'dev-2026.3.1',
            'dev-2026.4.1',
            '2026.3.1-rc-1',
            '2026.3.1-rc-2',
            '2026.4.1-rc-1',
            '2026.3.1',
            '2026.3.2',
        ],
    );
}

test('release selection defaults strictly to the newest stable version', function (): void {
    $selector = new ReleaseSelector();

    expect($selector->select(quarterlyReleaseCatalog())->canonical)->toBe('2026.3.2')
        ->and($selector->select(quarterlyReleaseCatalog(), 'stable')->canonical)->toBe('2026.3.2')
        ->and($selector->select(quarterlyReleaseCatalog(), 'rc')->canonical)->toBe('2026.4.1-rc-1')
        ->and($selector->select(quarterlyReleaseCatalog(), 'dev')->canonical)->toBe('dev-2026.4.1');
});

test('exact release selection opts into its channel and enforces an explicit channel match', function (): void {
    $selector = new ReleaseSelector();
    $catalog = quarterlyReleaseCatalog();

    expect($selector->select($catalog, exactVersion: '2026.3.1')->canonical)->toBe('2026.3.1')
        ->and($selector->select($catalog, exactVersion: '2026.3.1-rc-2')->canonical)->toBe('2026.3.1-rc-2')
        ->and($selector->select($catalog, exactVersion: 'dev-2026.3.1')->canonical)->toBe('dev-2026.3.1')
        ->and($selector->select($catalog, 'stable', '2026.3.1')->canonical)->toBe('2026.3.1')
        ->and($selector->select($catalog, 'rc', '2026.3.1-rc-2')->canonical)->toBe('2026.3.1-rc-2')
        ->and($selector->select($catalog, 'dev', 'dev-2026.3.1')->canonical)->toBe('dev-2026.3.1');
});

test('selection never falls back across channels', function (): void {
    $selector = new ReleaseSelector();
    $stableOnly = [ReleaseVersion::parse('2026.3.1')];

    expect(fn (): ReleaseVersion => $selector->select($stableOnly, 'rc'))
        ->toThrow(RuntimeException::class, 'No release is available in the "rc" channel.')
        ->and(fn (): ReleaseVersion => $selector->select($stableOnly, 'dev'))
        ->toThrow(RuntimeException::class, 'No release is available in the "dev" channel.')
        ->and(fn (): ReleaseVersion => $selector->select($stableOnly, ''))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty')
        ->and(fn (): ReleaseVersion => $selector->select($stableOnly, 'stable', '2026.3.1-rc-1'))
        ->toThrow(InvalidArgumentException::class, 'does not belong')
        ->and(fn (): ReleaseVersion => $selector->select($stableOnly, exactVersion: '2026.3.2'))
        ->toThrow(RuntimeException::class, 'is not available');
});
