<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Amasiye\Ppphp\Interop\Composer\ComposerResolver;
use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;
use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\CanonicalJson;

test('portable dependency indexes are deterministic source-free and readable', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/composer.lock', "{}\n");
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/contracts',
            'version' => '1.2.3.0',
            'pretty_version' => '1.2.3',
            'type' => 'library',
            'install_path' => '../acme/contracts',
            'autoload' => [
                'psr-4' => ['Acme\\' => 'src'],
                'files' => ['functions.php'],
            ],
            'require' => ['php' => '^8.4', 'ext-json' => '*'],
        ]],
        'dev' => true,
        'dev-package-names' => [],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/contracts/functions.php', <<<'PHP'
<?php
function acme_indexed(string $value): string { throw new LogicException('must not survive'); }
PHP);
    $this->writeFile($root . '/vendor/acme/contracts/src/Service.php', <<<'PHP'
<?php
namespace Acme;
/** @template T */
final class Service
{
    /** @param T $value @return T @throws \RuntimeException */
    public function apply(mixed $value): mixed { throw new \RuntimeException(); }
}
PHP);
    $projectSource = new SourceFile(
        $root . '/src/main.ppphp',
        'src/main.ppphp',
        FileKind::Ppphp,
        '<?php function use_index(\\Acme\\Service $service): string { return acme_indexed("x"); }',
    );
    $projectFile = (new PpphpParser())->parse($projectSource)->parsedFile;
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($projectFile)->not->toBeNull()->and($composer)->not->toBeNull();
    $declarations = (new ComposerDependencyDeclarationLoader())->load($composer, [$projectFile]);
    expect($declarations->isSuccessful)->toBeTrue();

    $first = $root . '/first-index';
    $second = $root . '/second-index';
    $writer = new DependencyDeclarationIndexWriter();
    $firstManifest = $writer->write($composer, $declarations, '8.4', $first);
    $secondManifest = $writer->write($composer, $declarations, '8.4', $second);

    expect(file_get_contents($first . '/manifest.json'))->toBe(file_get_contents($second . '/manifest.json'))
        ->and($firstManifest)->toBe($secondManifest)
        ->and(file_get_contents($first . '/manifest.json'))->not->toContain($root)
        ->and(implode("\n", array_map(
            static fn (string $path): string => file_get_contents($path) ?: '',
            glob($first . '/packages/*.json') ?: [],
        )))->not->toContain('must not survive', 'throw new');

    $restored = (new DependencyDeclarationIndexReader())->read(
        $first . '/manifest.json',
        '8.4',
        hash('sha256', file_get_contents($first . '/manifest.json') ?: ''),
    );

    expect($restored->isSuccessful)->toBeTrue()
        ->and($restored->parsedFiles)->toHaveCount(2)
        ->and($restored->knownClassPrefixes)->toContain('Acme\\');
});

test('portable dependency indexes fail atomically on target and shard corruption', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/manifest.json', "{}\n");

    $result = (new DependencyDeclarationIndexReader())->read($root . '/manifest.json', '8.4');

    expect($result->parsedFiles)->toBe([])
        ->and($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PortableDependencyIndexInvalid);
});

test('portable dependency indexes reject incompatible unsafe corrupt and inconsistent packages atomically', function (string $case): void {
    $fixture = dirname(__DIR__, 3) . '/tests/Fixtures/DependencyIndex/ppphp-dependencies';
    $root = $this->createTemporaryDirectory();
    $manifestPath = $root . '/manifest.json';
    $manifest = CanonicalJson::decode(file_get_contents($fixture . '/manifest.json') ?: '');
    $shardRelative = $manifest['packages'][0]['path'];
    $shardPath = $root . '/' . $shardRelative;
    $shard = CanonicalJson::decode(file_get_contents($fixture . '/' . $shardRelative) ?: '');
    $this->writeFile($manifestPath, CanonicalJson::encode($manifest));
    $this->writeFile($shardPath, CanonicalJson::encode($shard));
    $expectedHash = null;
    $persistShard = function () use (&$manifest, &$shard, $manifestPath, $shardPath): void {
        $shardJson = CanonicalJson::encode($shard);
        file_put_contents($shardPath, $shardJson);
        $manifest['packages'][0]['sha256'] = hash('sha256', $shardJson);
        $manifest['packages'][0]['counts'] = $shard['counts'];
        $manifest['counts'] = $shard['counts'];
        $identity = CanonicalJson::encode([
            'compilerVersion' => Compiler::VERSION,
            'declarationFormatVersion' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
            'packages' => $manifest['packages'],
            'targetPhpVersion' => '8.4',
        ]);
        $manifest['contentIdentity'] = 'sha256:' . hash('sha256', $identity);
        file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    };

    match ($case) {
        'format' => $manifest['formatVersion'] = 999,
        'declaration-format' => $manifest['declarationFormatVersion'] = 999,
        'target' => $manifest['targetPhpVersion'] = '8.3',
        'compiler' => $manifest['compiler']['version'] = 'incompatible',
        'manifest-hash' => $expectedHash = str_repeat('0', 64),
        'shard-hash' => file_put_contents($shardPath, CanonicalJson::encode($shard) . " \n"),
        'missing-shard' => unlink($shardPath),
        'duplicate-package' => $manifest['packages'][] = $manifest['packages'][0],
        'invalid-type' => $shard['documents'][0]['source'] = "<?php\nfunction invalid(array< \$value): void {}\n",
        'absolute-path' => $shard['documents'][0]['path'] = '/absolute.php',
        'traversal' => $shard['documents'][0]['path'] = '../escape.php',
        'count' => $shard['counts']['functions']++,
        'duplicate-declaration' => $shard['documents'][] = $shard['documents'][0],
        'alias-cycle' => $shard['aliases'] = [
            'Acme\\First' => ['autoloadForm' => 'files', 'order' => 0, 'original' => 'Acme\\Second', 'path' => 'functions.php'],
            'Acme\\Second' => ['autoloadForm' => 'files', 'order' => 0, 'original' => 'Acme\\First', 'path' => 'functions.php'],
        ],
        'size' => $shard['documents'][0]['source'] = "<?php\n/*" . str_repeat('x', ComposerDependencyDeclarationLoader::MAXIMUM_BYTES) . "*/\n",
    };

    if (in_array($case, ['format', 'declaration-format', 'target', 'compiler', 'duplicate-package'], true)) {
        file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    } elseif (in_array($case, [
        'invalid-type',
        'absolute-path',
        'traversal',
        'count',
        'duplicate-declaration',
        'alias-cycle',
        'size',
    ], true)) {
        if ($case === 'duplicate-declaration') {
            $shard['counts']['documents']++;
            $shard['counts']['functions']++;
        } elseif ($case === 'alias-cycle') {
            $shard['counts']['aliases'] = 2;
        }

        $persistShard();
    }

    $result = (new DependencyDeclarationIndexReader())->read($manifestPath, '8.4', $expectedHash);

    expect($result->parsedFiles)->toBe([])
        ->and($result->diagnostics->errors)->toHaveCount(1)
        ->and($result->diagnostics->errors[0]->code)->toBe(DiagnosticCode::PortableDependencyIndexInvalid);
})->with([
    'unsupported format' => 'format',
    'unsupported declaration format' => 'declaration-format',
    'wrong target' => 'target',
    'wrong compiler' => 'compiler',
    'manifest hash mismatch' => 'manifest-hash',
    'shard hash mismatch' => 'shard-hash',
    'missing shard' => 'missing-shard',
    'duplicate package' => 'duplicate-package',
    'invalid serialized type' => 'invalid-type',
    'absolute document path' => 'absolute-path',
    'traversing document path' => 'traversal',
    'count mismatch' => 'count',
    'duplicate declaration' => 'duplicate-declaration',
    'alias cycle' => 'alias-cycle',
    'size limit' => 'size',
]);
