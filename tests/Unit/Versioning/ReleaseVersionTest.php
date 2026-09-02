<?php

declare(strict_types=1);

use Amasiye\Ppphp\Versioning\Enumerations\ReleaseChannel;
use Amasiye\Ppphp\Versioning\ReleaseVersion;

test('canonical quarterly release versions parse and round trip', function (
    string $value,
    ReleaseChannel $channel,
    int $year,
    int $quarter,
    int $release,
    ?int $candidate,
): void {
    $version = ReleaseVersion::parse($value);

    expect($version->canonical)->toBe($value)
        ->and($version->toString())->toBe($value)
        ->and((string) $version)->toBe($value)
        ->and($version->year)->toBe($year)
        ->and($version->quarter)->toBe($quarter)
        ->and($version->releaseIncrement)->toBe($release)
        ->and($version->candidateIncrement)->toBe($candidate)
        ->and($version->core)->toBe(sprintf('%d.%d.%d', $year, $quarter, $release))
        ->and($version->channel)->toBe($channel)
        ->and($version->isStable)->toBe($channel === ReleaseChannel::Stable)
        ->and($version->isReleaseCandidate)->toBe($channel === ReleaseChannel::ReleaseCandidate)
        ->and($version->isDevelopment)->toBe($channel === ReleaseChannel::Development)
        ->and($version->isPrerelease)->toBe($channel !== ReleaseChannel::Stable);
})->with([
    'development' => ['dev-2026.3.1', ReleaseChannel::Development, 2026, 3, 1, null],
    'development next increment' => ['dev-2026.3.2', ReleaseChannel::Development, 2026, 3, 2, null],
    'release candidate' => ['2026.3.1-rc-1', ReleaseChannel::ReleaseCandidate, 2026, 3, 1, 1],
    'release candidate next candidate' => ['2026.3.1-rc-2', ReleaseChannel::ReleaseCandidate, 2026, 3, 1, 2],
    'stable' => ['2026.3.1', ReleaseChannel::Stable, 2026, 3, 1, null],
    'stable next increment' => ['2026.3.2', ReleaseChannel::Stable, 2026, 3, 2, null],
    'first quarter' => ['2031.1.1', ReleaseChannel::Stable, 2031, 1, 1, null],
    'fourth quarter' => ['2031.4.1', ReleaseChannel::Stable, 2031, 4, 1, null],
]);

test('noncanonical and mixed-channel release versions are rejected', function (string $value): void {
    expect(fn (): ReleaseVersion => ReleaseVersion::parse($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'retired placeholder' => ['development'],
    'bare development marker' => ['dev'],
    'short development core' => ['dev-2026.3'],
    'zero development release' => ['dev-2026.3.0'],
    'zero development quarter' => ['dev-2026.0.1'],
    'fifth development quarter' => ['dev-2026.5.1'],
    'leading-zero development quarter' => ['dev-2026.03.1'],
    'leading-zero development release' => ['dev-2026.3.01'],
    'uppercase development marker' => ['DEV-2026.3.1'],
    'short stable core' => ['2026.3'],
    'zero stable release' => ['2026.3.0'],
    'zero stable quarter' => ['2026.0.1'],
    'fifth stable quarter' => ['2026.5.1'],
    'leading-zero stable quarter' => ['2026.03.1'],
    'leading-zero stable release' => ['2026.3.01'],
    'v prefix' => ['v2026.3.1'],
    'missing candidate increment' => ['2026.3.1-rc'],
    'zero candidate increment' => ['2026.3.1-rc-0'],
    'leading-zero candidate increment' => ['2026.3.1-rc-01'],
    'dot candidate separator' => ['2026.3.1-rc.1'],
    'uppercase candidate marker' => ['2026.3.1-RC-1'],
    'development candidate mixture' => ['dev-2026.3.1-rc-1'],
    'suffix development marker' => ['2026.3.1-dev'],
]);

test('release versions compare numeric cores and only order within one channel', function (): void {
    expect(ReleaseVersion::parse('2026.3.1')->compareWithinChannel(ReleaseVersion::parse('2026.3.2')))->toBeLessThan(0)
        ->and(ReleaseVersion::parse('2026.4.1')->compareWithinChannel(ReleaseVersion::parse('2027.1.1')))->toBeLessThan(0)
        ->and(ReleaseVersion::parse('dev-2026.3.2')->compareWithinChannel(ReleaseVersion::parse('dev-2026.4.1')))->toBeLessThan(0)
        ->and(ReleaseVersion::parse('2026.3.1-rc-1')->compareCandidate(ReleaseVersion::parse('2026.3.1-rc-2')))->toBeLessThan(0)
        ->and(ReleaseVersion::parse('2026.3.1-rc-2')->compareWithinChannel(ReleaseVersion::parse('2026.3.2-rc-1')))->toBeLessThan(0)
        ->and(ReleaseVersion::parse('dev-2026.3.1')->compareCore(ReleaseVersion::parse('2026.3.1')))->toBe(0);
});

test('implicit cross-channel and cross-core candidate comparison is refused', function (): void {
    expect(fn (): int => ReleaseVersion::parse('dev-2026.3.1')->compareWithinChannel(
        ReleaseVersion::parse('2026.3.1-rc-1'),
    ))->toThrow(LogicException::class, 'different channels')
        ->and(fn (): int => ReleaseVersion::parse('2026.3.1-rc-2')->compareCandidate(
            ReleaseVersion::parse('2026.3.2-rc-1'),
        ))->toThrow(LogicException::class, 'one exact release core');
});
