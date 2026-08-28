<?php

declare(strict_types=1);

use Amasiye\Phplus\Config\ProjectConfigLoader;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;

function configurationDiagnosticCodes(string $root, ?string $configurationPath = null, bool $requireSources = false): array
{
    $result = (new ProjectConfigLoader())->load($root, $configurationPath, $requireSources);

    return array_map(
        static fn ($diagnostic): DiagnosticCode => $diagnostic->code,
        iterator_to_array($result->diagnostics),
    );
}

test('valid configuration loads normalized absolute project paths', function (): void {
    $root = $this->temporaryDirectory();
    $this->createDirectory($root . '/src');
    $this->writeConfiguration($root, [
        'source' => ['./src/../src'],
        'output' => './build/../build/phplus',
    ]);

    $result = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true);
    $realRoot = (string) realpath($root);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->configuration)->not->toBeNull()
        ->and($result->configuration?->projectRoot)->toBe($realRoot)
        ->and($result->configuration?->configurationPath)->toBe($realRoot . '/phplus.json')
        ->and($result->configuration?->sourceRoots)->toBe([$realRoot . '/src'])
        ->and($result->configuration?->outputPath)->toBe($realRoot . '/build/phplus')
        ->and($result->configuration?->cachePath)->toBe($realRoot . '/.phplus-cache');
});

test('an explicit relative configuration path resolves from the project root', function (): void {
    $root = $this->temporaryDirectory();
    $this->createDirectory($root . '/src');
    $defaultPath = $this->writeConfiguration($root);
    $this->createDirectory($root . '/config');
    rename($defaultPath, $root . '/config/project.json');

    $result = (new ProjectConfigLoader())->load($root, 'config/project.json', true);
    $realRoot = (string) realpath($root);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->configuration?->configurationPath)->toBe($realRoot . '/config/project.json');
});

test('an existing optional schema string remains valid without being fetched', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, [
        '$schema' => 'https://invalid.example.test/schema.json',
    ]);

    $result = (new ProjectConfigLoader())->load($root);

    expect($result->isSuccessful())->toBeTrue();
});

test('missing and malformed configuration inputs produce stable diagnostics', function (string $kind, DiagnosticCode $expected): void {
    $root = $this->temporaryDirectory();

    if ($kind === 'invalid-json') {
        $this->writeFile($root . '/phplus.json', '{');
    } elseif ($kind === 'non-object') {
        $this->writeFile($root . '/phplus.json', '["src"]');
    }

    expect(configurationDiagnosticCodes($root))->toContain($expected);
})->with([
    'missing file' => ['missing', DiagnosticCode::ProjectConfigurationNotFound],
    'invalid JSON' => ['invalid-json', DiagnosticCode::InvalidProjectConfigurationJson],
    'non-object root' => ['non-object', DiagnosticCode::InvalidConfigurationPropertyType],
]);

test('configuration property validation rejects malformed values', function (array $overrides, DiagnosticCode $expected): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, $overrides);

    expect(configurationDiagnosticCodes($root))->toContain($expected);
})->with([
    'unknown property' => [['strictness' => 'maximum'], DiagnosticCode::UnknownConfigurationProperty],
    'wrong scalar type' => [['output' => 42], DiagnosticCode::InvalidConfigurationPropertyType],
    'wrong array element type' => [['source' => ['src', 42]], DiagnosticCode::InvalidConfigurationPropertyType],
    'empty source array' => [['source' => []], DiagnosticCode::InvalidConfigurationPropertyType],
    'duplicate source entry' => [['source' => ['src', 'src']], DiagnosticCode::InvalidConfigurationPropertyType],
    'normalized duplicate source entry' => [['source' => ['src', './src']], DiagnosticCode::InvalidConfigurationPropertyType],
    'unsupported target' => [['targetPhpVersion' => '8.3'], DiagnosticCode::UnsupportedTargetPhpVersion],
]);

test('missing required configuration properties are diagnosed', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeFile($root . '/phplus.json', json_encode([
        'source' => ['src'],
        'output' => 'build/phplus',
        'targetPhpVersion' => '8.4',
    ], JSON_THROW_ON_ERROR));

    expect(configurationDiagnosticCodes($root))->toContain(DiagnosticCode::MissingConfigurationProperty);
});

test('unsafe traversals and configured overlaps are rejected', function (array $overrides, DiagnosticCode $expected): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, $overrides);

    expect(configurationDiagnosticCodes($root))->toContain($expected);
})->with([
    'source parent traversal' => [['source' => ['../source']], DiagnosticCode::UnsafeProjectPath],
    'output outside root' => [['output' => '../output'], DiagnosticCode::UnsafeProjectPath],
    'cache outside root' => [['cache' => '../cache'], DiagnosticCode::UnsafeProjectPath],
    'output source overlap' => [['output' => 'src/build'], DiagnosticCode::ConfiguredPathsOverlap],
    'cache source overlap' => [['cache' => 'src/cache'], DiagnosticCode::ConfiguredPathsOverlap],
    'output cache overlap' => [['output' => 'build', 'cache' => 'build/cache'], DiagnosticCode::ConfiguredPathsOverlap],
]);

test('source roots must exist and be directories for frontend commands', function (string $kind, DiagnosticCode $expected): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);

    if ($kind === 'file') {
        $this->writeFile($root . '/src', 'not a directory');
    }

    expect(configurationDiagnosticCodes($root, requireSources: true))->toContain($expected);
})->with([
    'missing source directory' => ['missing', DiagnosticCode::SourcePathDoesNotExist],
    'source path is a file' => ['file', DiagnosticCode::SourcePathNotDirectory],
]);

test('configuration paths outside the project root are rejected', function (): void {
    $root = $this->temporaryDirectory();

    expect(configurationDiagnosticCodes($root, '../phplus.json'))->toContain(DiagnosticCode::UnsafeProjectPath);
});
