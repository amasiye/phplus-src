<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Amasiye\Ppphp\Interop\Composer\ComposerResolver;
use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;
use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;

function parseComposerCompletionSource(string $root, string $source): \Amasiye\Ppphp\Frontend\ParsedFile
{
    $file = new SourceFile($root . '/src/main.ppphp', 'src/main.ppphp', FileKind::Ppphp, $source);
    $parsed = (new PpphpParser())->parse($file)->parsedFile;

    if ($parsed === null) {
        throw new RuntimeException('The test source could not be parsed.');
    }

    return $parsed;
}

test('Composer metadata retains ordered PSR-0 exclusions and package identity', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'legacy/package',
            'version' => '1.2.3.0',
            'pretty_version' => '1.2.3',
            'type' => 'library',
            'install_path' => '../legacy/package',
            'source' => ['reference' => 'abc123'],
            'autoload' => [
                'psr-0' => ['Legacy_' => ['z', 'a']],
                'classmap' => ['classmap/*/lib'],
                'exclude-from-classmap' => ['/classmap/ignored/'],
            ],
            'require' => ['php' => '^8.4', 'ext-json' => '*'],
        ]],
        'dev' => true,
        'dev-package-names' => ['legacy/package'],
    ], JSON_THROW_ON_ERROR));

    $package = (new ComposerResolver())->resolve($root)->project?->dependencies[0] ?? null;

    expect($package)->not->toBeNull()
        ->and($package?->autoload->psr0['Legacy_'])->toBe([
            $root . '/vendor/legacy/package/z',
            $root . '/vendor/legacy/package/a',
        ])->and($package?->autoload->classmap)->toBe([$root . '/vendor/legacy/package/classmap/*/lib'])
        ->and($package?->autoload->excludeFromClassmap)->toBe([$root . '/vendor/legacy/package/classmap/ignored/**'])
        ->and($package?->prettyVersion)->toBe('1.2.3')
        ->and($package?->reference)->toBe('abc123')
        ->and($package?->type)->toBe('library')
        ->and($package?->developmentOnly)->toBeTrue()
        ->and($package?->extensionRequirements)->toBe(['ext-json' => '*'])
        ->and($package?->installedMetadataIdentity)->toStartWith('sha256:');
});

test('PSR-4 wins before PSR-0 while PSR-0 supports namespaced PEAR and empty prefixes', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'legacy/package',
            'install_path' => '../legacy/package',
            'autoload' => [
                'psr-4' => ['Legacy\\' => 'modern'],
                'psr-0' => ['Legacy\\' => 'old', 'Vendor_' => 'pear', '' => 'fallback'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/legacy/package/modern/Clock.php', '<?php namespace Legacy; final class Clock {}');
    $this->writeFile($root . '/vendor/legacy/package/old/Legacy/Clock.php', '<?php namespace Legacy; final class Clock {}');
    $this->writeFile($root . '/vendor/legacy/package/pear/Vendor/Tool/Clock.php', '<?php final class Vendor_Tool_Clock {}');
    $this->writeFile($root . '/vendor/legacy/package/fallback/Loose/Thing.php', '<?php namespace Loose; final class Thing {}');
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $parsed = parseComposerCompletionSource($root, '<?php function take(\\Legacy\\Clock $a, \\Vendor_Tool_Clock $b, \\Loose\\Thing $c): void {}');
    $result = (new ComposerDependencyDeclarationLoader())->load($project, [$parsed]);
    $paths = array_column(array_map(
        static fn ($file): array => ['path' => $file->sourceFile->displayPath],
        $result->parsedFiles,
    ), 'path');

    expect($result->isSuccessful)->toBeTrue()
        ->and($paths)->toContain(
            '<Composer legacy/package>/modern/Clock.php',
            '<Composer legacy/package>/pear/Vendor/Tool/Clock.php',
            '<Composer legacy/package>/fallback/Loose/Thing.php',
        )->and($paths)->not->toContain('<Composer legacy/package>/old/Legacy/Clock.php');
});

test('classmap discovery applies wildcards and Composer exclusions', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/maps',
        'install_path' => '../acme/maps',
        'autoload' => [
            'classmap' => ['addons/*/lib', 'tree'],
            'exclude-from-classmap' => ['/tree/ignored'],
        ],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/maps/addons/one/lib/One.php', '<?php final class One {}');
    $this->writeFile($root . '/vendor/acme/maps/addons/one/no/No.php', '<?php final class No {}');
    $this->writeFile($root . '/vendor/acme/maps/tree/Used.php', '<?php final class Used {}');
    $this->writeFile($root . '/vendor/acme/maps/tree/ignored/Ignored.php', '<?php final class Ignored {}');
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $result = (new ComposerDependencyDeclarationLoader())->load($project, []);
    $declarations = (new \Amasiye\Ppphp\Analysis\Declaration\DeclarationReferenceCollector())
        ->collectDeclarations($result->parsedFiles)['classes'];

    expect($result->isSuccessful)->toBeTrue()
        ->and($declarations)->toBe(['One', 'Used']);
});

test('static includes guarded polyfills and aliases share normal semantic contracts without execution', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/context',
        'install_path' => '../acme/context',
        'autoload' => ['files' => ['bootstrap.php'], 'psr-4' => ['Acme\\' => 'src']],
    ]]], JSON_THROW_ON_ERROR));
    $marker = $root . '/executed';
    $this->writeFile($root . '/vendor/acme/context/bootstrap.php', sprintf(<<<'PHP'
<?php
file_put_contents(%s, 'bad');
require __DIR__ . '/included.php';
if (!function_exists('acme_fallback')) {
    function acme_fallback(string $value): string { throw new LogicException(); }
}
class_alias(Acme\Service::class, Acme\AliasService::class);
PHP, var_export($marker, true)));
    $this->writeFile($root . '/vendor/acme/context/included.php', '<?php const ACME_INCLUDED = "ok";');
    $this->writeFile($root . '/vendor/acme/context/src/Service.php', '<?php namespace Acme; final class Service { public function value(): string { throw new \\LogicException(); } }');
    $this->writeFile($root . '/src/main.ppphp', <<<'PHP'
<?php
function run(\Acme\AliasService $service): string { return acme_fallback(ACME_INCLUDED . $service->value()); }
PHP);

    $response = (new CompilerAnalysisProtocol())->analyze(new CompilerAnalysisRequest('composer-edge', null), $root)->toArray();

    expect($response['diagnostics']['diagnostics'])->toBe([])
        ->and(file_exists($marker))->toBeFalse();
});

test('ambiguous dependency declarations fail explicitly instead of insertion-order selection', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/duplicates',
        'install_path' => '../acme/duplicates',
        'autoload' => ['files' => ['first.php', 'second.php']],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/duplicates/first.php', '<?php function acme_duplicate(): string { return "first"; }');
    $this->writeFile($root . '/vendor/acme/duplicates/second.php', '<?php function acme_duplicate(): string { return "second"; }');
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $result = (new ComposerDependencyDeclarationLoader())->load($project, []);

    expect($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::DependencyDeclarationAmbiguous);
});

test('deterministic Composer lookup precedence survives complete index discovery', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/ordered',
        'install_path' => '../acme/ordered',
        'autoload' => ['psr-4' => ['Acme\\Ordered\\' => ['first', 'second']]],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile(
        $root . '/vendor/acme/ordered/first/Service.php',
        '<?php namespace Acme\\Ordered; final class Service { public string $winner; }',
    );
    $this->writeFile(
        $root . '/vendor/acme/ordered/second/Service.php',
        '<?php namespace Acme\\Ordered; final class Service { public int $winner; }',
    );
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $declarations = (new ComposerDependencyDeclarationLoader())->load($project, [], completeIndex: true);
    expect($declarations->isSuccessful)->toBeTrue();

    $output = $root . '/ppphp-dependencies';
    (new DependencyDeclarationIndexWriter())->write($project, $declarations, '8.4', $output);
    $restored = (new DependencyDeclarationIndexReader())->read($output . '/manifest.json', '8.4');
    $shards = glob($output . '/packages/*.json') ?: [];
    $serialized = implode("\n", array_map(
        static fn (string $path): string => file_get_contents($path) ?: '',
        $shards,
    ));

    expect($restored->isSuccessful)->toBeTrue()
        ->and($serialized)->toContain('public string $winner')
        ->and($serialized)->not->toContain('public int $winner');
});

test('literal cross-package alias chains retain declaration-site provenance', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [
        [
            'name' => 'acme/aliases',
            'install_path' => '../acme/aliases',
            'autoload' => ['files' => ['aliases.php']],
        ],
        [
            'name' => 'acme/contracts',
            'install_path' => '../acme/contracts',
            'autoload' => ['psr-4' => ['Acme\\Contracts\\' => 'src']],
        ],
    ]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/aliases/aliases.php', <<<'PHP'
<?php
class_alias('Acme\Contracts\Service', 'Acme\Aliases\Service');
class_alias('Acme\Aliases\Service', 'Acme\Aliases\ChainedService');
PHP);
    $this->writeFile(
        $root . '/vendor/acme/contracts/src/Service.php',
        '<?php namespace Acme\\Contracts; final class Service { public function value(): string { throw new \\LogicException(); } }',
    );
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $parsed = parseComposerCompletionSource(
        $root,
        '<?php function read(\\Acme\\Aliases\\ChainedService $service): string { return $service->value(); }',
    );
    $declarations = (new ComposerDependencyDeclarationLoader())->load($project, [$parsed]);
    expect($declarations->isSuccessful)->toBeTrue();
    $output = $root . '/ppphp-dependencies';
    (new DependencyDeclarationIndexWriter())->write($project, $declarations, '8.4', $output);
    $manifest = json_decode(file_get_contents($output . '/manifest.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
    $aliasEntry = $manifest['packages'][0];
    $aliasShard = json_decode(
        file_get_contents($output . '/' . $aliasEntry['path']) ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $restored = (new DependencyDeclarationIndexReader())->read($output . '/manifest.json', '8.4');
    $restoredAliases = $restored->classAliases;
    $sourceAliases = $declarations->classAliases;
    ksort($restoredAliases, SORT_STRING);
    ksort($sourceAliases, SORT_STRING);

    expect($aliasEntry['name'])->toBe('acme/aliases')
        ->and($aliasShard['aliases']['Acme\\Aliases\\Service']['path'])->toBe('aliases.php')
        ->and($aliasShard['aliases']['Acme\\Aliases\\ChainedService']['original'])->toBe('Acme\\Aliases\\Service')
        ->and($restoredAliases)->toBe($sourceAliases);
});

test('unresolvable static and dynamic aliases expose relevant unavailable context', function (string $aliasSource, string $referenced): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [
        [
            'name' => 'acme/aliases',
            'install_path' => '../acme/aliases',
            'autoload' => ['files' => ['aliases.php']],
        ],
        [
            'name' => 'acme/contracts',
            'install_path' => '../acme/contracts',
            'autoload' => ['psr-4' => ['Acme\\Contracts\\' => 'src']],
        ],
    ]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/aliases/aliases.php', $aliasSource);
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $parsed = parseComposerCompletionSource(
        $root,
        sprintf('<?php function consume(\\%s $value): void {}', $referenced),
    );
    $result = (new ComposerDependencyDeclarationLoader())->load($project, [$parsed]);

    expect($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::DependencyDeclarationContextUnavailable);
})->with([
    'missing static original' => [
        "<?php\nclass_alias('Acme\\\\Contracts\\\\Missing', 'Acme\\\\Aliases\\\\MissingAlias');\n",
        'Acme\\Aliases\\MissingAlias',
    ],
    'dynamic alias' => [
        "<?php\n\$alias = 'Acme\\\\Aliases\\\\DynamicAlias';\nclass_alias('Acme\\\\Contracts\\\\Missing', \$alias);\n",
        'Acme\\Aliases\\DynamicAlias',
    ],
]);

test('alias cycles and concrete alias collisions are dependency ambiguities', function (string $source): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/aliases',
        'install_path' => '../acme/aliases',
        'autoload' => ['files' => ['aliases.php']],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/aliases/aliases.php', $source);
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $result = (new ComposerDependencyDeclarationLoader())->load($project, []);

    expect($result->diagnostics->errors)->not->toBe([])
        ->and(array_column($result->diagnostics->errors, 'code'))->toContain(DiagnosticCode::DependencyDeclarationAmbiguous);
})->with([
    'cycle' => "<?php\nclass_alias('Acme\\\\Second', 'Acme\\\\First');\nclass_alias('Acme\\\\First', 'Acme\\\\Second');\n",
    'concrete collision' => "<?php\nclass Original {}\nclass AliasName {}\nclass_alias(Original::class, AliasName::class);\n",
]);

test('dependency symlinks cannot escape canonical trusted roots when selected source needs them', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $outside = $container . '/outside';
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'acme/unsafe',
        'install_path' => '../acme/unsafe',
        'autoload' => ['psr-4' => ['Acme\\Unsafe\\' => 'src']],
    ]]], JSON_THROW_ON_ERROR));
    $this->writeFile($outside . '/src/Thing.php', '<?php namespace Acme\\Unsafe; final class Thing {}');
    mkdir($root . '/vendor/acme', 0777, true);
    symlink($outside, $root . '/vendor/acme/unsafe');
    $project = (new ComposerResolver())->resolve($root)->project;
    expect($project)->not->toBeNull();
    $parsed = parseComposerCompletionSource($root, '<?php function take(\\Acme\\Unsafe\\Thing $thing): void {}');
    $result = (new ComposerDependencyDeclarationLoader())->load($project, [$parsed]);

    expect($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::DependencySourcePathUnsafe);
});

test('dependency install files and includes remain inside their canonical package roots', function (): void {
    foreach (['install-path', 'autoload-file', 'include'] as $case) {
        $container = $this->createTemporaryDirectory();
        $root = $container . '/project';
        $outside = $container . '/outside';
        $packageRoot = $root . '/vendor/acme/unsafe';
        $installPath = $case === 'install-path' ? '../../../outside' : '../acme/unsafe';
        $autoload = $case === 'install-path'
            ? ['psr-4' => ['Acme\\Unsafe\\' => 'src']]
            : ['files' => [$case === 'autoload-file' ? '../../../outside/functions.php' : 'bootstrap.php']];
        $source = $case === 'install-path'
            ? '<?php function take(\\Acme\\Unsafe\\Thing $thing): void {}'
            : '<?php function take(): void { acme_unsafe_missing(); }';

        $this->writeFile($root . '/composer.json', '{}');
        $this->writeFile($root . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'acme/unsafe',
            'install_path' => $installPath,
            'autoload' => $autoload,
        ]]], JSON_THROW_ON_ERROR));
        $this->writeFile($outside . '/src/Thing.php', '<?php namespace Acme\\Unsafe; final class Thing {}');
        $this->writeFile($outside . '/functions.php', '<?php function acme_unsafe_missing(): void {}');

        if ($case === 'autoload-file') {
            $this->writeFile($packageRoot . '/placeholder.txt', 'package root');
        } elseif ($case === 'include') {
            $this->writeFile($packageRoot . '/bootstrap.php', "<?php require __DIR__ . '/../../../../outside/functions.php';\n");
        }

        $project = (new ComposerResolver())->resolve($root)->project;
        expect($project)->not->toBeNull();
        $result = (new ComposerDependencyDeclarationLoader())->load(
            $project,
            [parseComposerCompletionSource($root, $source)],
        );

        $this->assertContains(
            DiagnosticCode::DependencySourcePathUnsafe,
            array_column($result->diagnostics->errors, 'code'),
            $case,
        );
    }
});
