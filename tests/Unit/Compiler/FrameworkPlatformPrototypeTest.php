<?php

declare(strict_types=1);

use Tests\Support\FrameworkIntegrationSpike\PlatformProfile;

test('platform spike separates older host from reviewed newer syntax signatures emission and runtime', function (): void {
    $profile = new PlatformProfile('8.4', '8.5', '8.5', '8.5', '8.5');
    foreach (['hooks', 'find', 'pipe', 'first'] as $fixture) {
        $profile->validateSpecimen((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/FrameworkIntegration/PlatformProbe/' . $fixture . '.php'));
    }
    expect($profile->host)->toBe('8.4')->and($profile->emission)->toBe('8.5');
});

test('platform spike rejects incompatible specimen capabilities with specific diagnostics', function (array $versions, string $fixture, string $diagnostic): void {
    $profile = new PlatformProfile(...$versions);
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/FrameworkIntegration/PlatformProbe/' . $fixture . '.php');
    expect(fn () => $profile->validateSpecimen($source))->toThrow(DomainException::class, $diagnostic);
})->with([
    [['8.4', '8.4', '8.4', '8.4', '8.4'], 'pipe', 'SPIKE_PARSE: Syntax error'],
    [['8.4', '8.5', '8.5', '8.4', '8.5'], 'pipe', 'SPIKE_NATIVE_EMISSION_REQUIRES_8_5'],
    [['8.4', '8.5', '8.5', '8.5', '8.4'], 'pipe', 'SPIKE_NATIVE_RUNTIME_REQUIRES_8_5'],
    [['8.5', '8.5', '8.4', '8.5', '8.5'], 'first', 'SPIKE_API_REQUIRES_8_5_SIGNATURES'],
    [['8.5', '8.5', '8.5', '8.5', '8.4'], 'first', 'SPIKE_API_REQUIRES_8_5_RUNTIME'],
]);

test('platform spike rejects unreviewed platforms and unmet application requirements', function (): void {
    expect(fn () => new PlatformProfile('8.4', '8.6', '8.5', '8.5', '8.5'))
        ->toThrow(InvalidArgumentException::class, 'SPIKE_UNREVIEWED_PLATFORM');
    $profile = new PlatformProfile('8.4', '8.4', '8.4', '8.4', '8.4');
    expect(fn () => $profile->validateRuntimeRequirements('8.5', [], []))
        ->toThrow(DomainException::class, 'SPIKE_DEPENDENCY_RUNTIME_TOO_OLD')
        ->and(fn () => $profile->validateRuntimeRequirements('8.4', ['openswoole'], ['json']))
        ->toThrow(DomainException::class, 'SPIKE_REQUIRED_EXTENSION_MISSING');
    $profile->validateRuntimeRequirements('8.4', ['json'], ['json']);
});

test('platform spike partitions proposed cache evidence across all platform inputs', function (): void {
    $base = ['8.4', '8.4', '8.4', '8.4', '8.4'];
    $profile = new PlatformProfile(...$base);
    $identity = $profile->calculateIdentity('revision', 'parser', 'sig', 'lock', ['json' => '8.4']);
    $evidence = [$identity => ['analysis' => 'old analysis', 'build' => 'old output']];
    expect($evidence[$profile->calculateIdentity('revision', 'parser', 'sig', 'lock', ['json' => '8.4'])])
        ->toBe(['analysis' => 'old analysis', 'build' => 'old output']);
    foreach (array_keys($base) as $key) {
        $changed = $base;
        $changed[$key] = '8.5';
        $key = (new PlatformProfile(...$changed))->calculateIdentity('revision', 'parser', 'sig', 'lock', ['json' => '8.4']);
        expect($key)->not->toBe($identity)->and($evidence[$key] ?? null)->toBeNull();
    }
    foreach ([['other', 'parser', 'sig', 'lock', ['json' => '8.4']], ['revision', 'other', 'sig', 'lock', ['json' => '8.4']], ['revision', 'parser', 'other', 'lock', ['json' => '8.4']], ['revision', 'parser', 'sig', 'other', ['json' => '8.4']], ['revision', 'parser', 'sig', 'lock', ['json' => '8.5']]] as $inputs) {
        $key = $profile->calculateIdentity(...$inputs);
        expect($key)->not->toBe($identity)->and($evidence[$key] ?? null)->toBeNull();
    }
    expect($profile->calculateIdentity('revision', 'parser', 'sig', 'lock', ['z' => '1', 'a' => '2']))
        ->toBe($profile->calculateIdentity('revision', 'parser', 'sig', 'lock', ['a' => '2', 'z' => '1']));
});
