<?php

declare(strict_types=1);

use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Interop\Composer\ComposerConfigurationWriter;
use Amasiye\Ppphp\Interop\Composer\ComposerResolver;
use Amasiye\Ppphp\Interop\Composer\ComposerRuntimeConfigurator;

test('Composer runtime projection preserves source metadata and handles every root mapping form idempotently', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, [
        'source' => ['src', 'tests'],
        'output' => 'var/generated',
    ]);
    $this->createDirectory($root . '/src/Domain');
    $this->createDirectory($root . '/tests');
    $this->createDirectory($root . '/legacy');
    $this->writeFile($root . '/src/bootstrap.php', '<?php');
    $this->writeFile($root . '/src/functions.ppphp', '<?php');
    $this->writeFile($root . '/outside.php', '<?php');
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'autoload' => [
            'psr-4' => [
                'App\\' => 'src/',
                'Domain\\' => ['src/Domain/', 'legacy/'],
            ],
            'classmap' => ['src/Domain', 'legacy'],
            'files' => ['src/bootstrap.php', 'src/functions.ppphp', 'outside.php'],
        ],
        'autoload-dev' => [
            'psr-4' => ['Tests\\' => ['tests/']],
        ],
        'extra' => ['preserved' => true],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $configResult = (new ProjectConfigLoader())->load($root, null, true);
    expect($configResult->configuration)->not->toBeNull();
    $configuration = $configResult->configuration;
    $projection = (new ComposerRuntimeConfigurator())->project($configuration);

    expect($projection->isSuccessful)->toBeTrue()
        ->and($projection->isChanged)->toBeTrue()
        ->and($projection->unprojectedMappings)->toHaveCount(6);

    $projected = json_decode((string) $projection->projectedContents, true, flags: JSON_THROW_ON_ERROR);

    expect($projected['autoload']['psr-4']['App\\'])->toBe('var/generated/')
        ->and($projected['autoload']['psr-4']['Domain\\'])->toBe(['var/generated/Domain/', 'legacy/'])
        ->and($projected['autoload']['classmap'])->toBe(['var/generated/Domain', 'legacy'])
        ->and($projected['autoload']['files'])->toBe([
            'var/generated/bootstrap.php',
            'var/generated/functions.php',
            'outside.php',
        ])
        ->and($projected['autoload-dev']['psr-4']['Tests\\'])->toBe(['var/generated/'])
        ->and($projected['extra']['preserved'])->toBeTrue()
        ->and($projected['extra']['ppphp']['source-autoload']['psr-4']['App\\'])->toBe('src/')
        ->and($projected['extra']['ppphp']['source-autoload']['psr-4']['Domain\\'])->toBe(['src/Domain/'])
        ->and($projected['extra']['ppphp']['source-autoload']['classmap'])->toBe(['src/Domain'])
        ->and($projected['extra']['ppphp']['source-autoload']['files'])->toBe(['src/bootstrap.php', 'src/functions.ppphp'])
        ->and($projected['extra']['ppphp']['source-autoload-dev']['psr-4']['Tests\\'])->toBe(['tests/']);

    $writeDiagnostics = (new ComposerConfigurationWriter())->write($projection, $configuration->projectRoot);
    expect($writeDiagnostics->isEmpty)->toBeTrue();

    $repeated = (new ComposerRuntimeConfigurator())->project($configuration);
    expect($repeated->isSuccessful)->toBeTrue()
        ->and($repeated->isChanged)->toBeFalse()
        ->and($repeated->unprojectedMappings)->toBe([]);

    $projectRoot = $configuration->projectRoot;
    $resolved = (new ComposerResolver())->resolve($projectRoot, [
        $configuration->outputPath,
        $configuration->cachePath,
    ]);
    expect($resolved->isSuccessful)->toBeTrue()
        ->and($resolved->project?->projectAutoload->psr4['App\\'])->toContain($projectRoot . '/src')
        ->and($resolved->project?->projectAutoload->psr4['Domain\\'])->toContain($projectRoot . '/src/Domain')
        ->toContain($projectRoot . '/legacy')
        ->not->toContain($projectRoot . '/var/generated/Domain')
        ->and($resolved->project?->projectAutoload->files)->toContain($projectRoot . '/src/functions.ppphp')
        ->toContain($projectRoot . '/outside.php')
        ->not->toContain($projectRoot . '/var/generated/functions.php')
        ->and($resolved->project?->projectAutoload->paths)->not->toContain($projectRoot . '/var/generated');
});

test('preserved source metadata detects a conflicting runtime mapping', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $this->writeFile($root . '/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'somewhere-else/']],
        'extra' => ['ppphp' => [
            'source-autoload' => [
                'psr-4' => ['App\\' => 'src/'],
                'classmap' => [],
                'files' => [],
            ],
            'source-autoload-dev' => [
                'psr-4' => new stdClass(),
                'classmap' => [],
                'files' => [],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();

    $projection = (new ComposerRuntimeConfigurator())->project($configuration);

    expect($projection->isSuccessful)->toBeFalse()
        ->and($projection->diagnostics->errors[0]->code)->toBe(DiagnosticCode::ComposerRuntimeMappingConflictsWithBuildOutput);
});
