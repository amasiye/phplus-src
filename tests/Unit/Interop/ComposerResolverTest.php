<?php

declare(strict_types=1);

use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Interop\Composer\ComposerResolver;

test('Composer resolution records project and installed-package autoload context without executing it', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/src/App.php', '<?php');
    $this->writeFile($root . '/tests/AppTest.php', '<?php');
    $this->writeFile($root . '/bootstrap.php', '<?php throw new RuntimeException("must not execute");');
    $this->writeFile($root . '/classmap/ClassOne.php', '<?php');
    $this->writeFile($root . '/classmap/ignore.txt', 'ignored');
    $this->writeFile($root . '/packages/vendor/package/src/Dependency.php', '<?php');
    $this->writeFile($root . '/composer.json', json_encode([
        'autoload' => [
            'psr-4' => ['App\\' => 'src'],
            'classmap' => ['classmap'],
            'files' => ['bootstrap.php'],
        ],
        'autoload-dev' => ['psr-4' => ['Tests\\' => ['tests']]],
        'config' => ['vendor-dir' => 'packages'],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/packages/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'vendor/package',
            'install_path' => '../vendor/package',
            'autoload' => ['psr-4' => ['Vendor\\Package\\' => 'src']],
            'autoload-dev' => ['psr-4' => ['Vendor\\Package\\Tests\\' => 'tests']],
        ]],
    ], JSON_THROW_ON_ERROR));

    $result = (new ComposerResolver())->resolve($root);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->project?->vendorPath)->toBe($root . '/packages')
        ->and($result->project?->projectAutoload->psr4['App\\'])->toBe([$root . '/src'])
        ->and($result->project?->projectAutoload->psr4['Tests\\'])->toBe([$root . '/tests'])
        ->and($result->project?->projectAutoload->classmap)->toBe([$root . '/classmap/ClassOne.php'])
        ->and($result->project?->projectAutoload->files)->toBe([$root . '/bootstrap.php'])
        ->and($result->project?->dependencyAutoload->psr4['Vendor\\Package\\'])->toBe([
            $root . '/packages/vendor/package/src',
        ])->and($result->project?->dependencyAutoload->psr4)->not->toHaveKey('Vendor\\Package\\Tests\\');
});

test('a project without Composer metadata or an installed vendor directory remains valid', function (): void {
    $root = $this->createTemporaryDirectory();

    $result = (new ComposerResolver())->resolve($root);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->project?->configurationPath)->toBeNull()
        ->and($result->project?->projectAutoload->paths)->toBe([]);
});

test('malformed installed package metadata uses the installed-metadata diagnostic', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', '{');

    $result = (new ComposerResolver())->resolve($root);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::InvalidInstalledComposerMetadata);
});

test('malformed Composer files and autoload mappings produce structured diagnostics', function (string $kind, DiagnosticCode $code): void {
    $root = $this->createTemporaryDirectory();

    if ($kind === 'json') {
        $this->writeFile($root . '/composer.json', '{');
    } else {
        $this->writeFile($root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => [42]]],
        ], JSON_THROW_ON_ERROR));
    }

    $result = (new ComposerResolver())->resolve($root);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code)->toBe($code);
})->with([
    'malformed json' => ['json', DiagnosticCode::InvalidComposerConfiguration],
    'malformed autoload' => ['autoload', DiagnosticCode::InvalidComposerAutoloadMapping],
]);
