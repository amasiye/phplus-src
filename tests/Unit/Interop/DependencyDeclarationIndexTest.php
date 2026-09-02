<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Atatusoft\Ppphp\Interop\Composer\ComposerResolver;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Atatusoft\Ppphp\Interop\Composer\Index\DeclarationCompatibilityIdentity;
use Atatusoft\Ppphp\Interop\Composer\Index\PortableDeclarationValidator;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\CanonicalJson;

/** @return array{Atatusoft\Ppphp\Interop\Composer\ComposerProject, Atatusoft\Ppphp\Project\ProjectParseResult} */
function loadStageThirteenDPortableIndexInputs(string $root): array
{
    $projectSource = new SourceFile(
        $root . '/src/main.ppphp',
        'src/main.ppphp',
        FileKind::Ppphp,
        '<?php function use_portable_index(\\Acme\\Service $service): string { return acme_portable("x"); }',
    );
    $projectFile = (new PpphpParser())->parse($projectSource)->parsedFile;
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($projectFile)->not->toBeNull()->and($composer)->not->toBeNull();
    $declarations = (new ComposerDependencyDeclarationLoader())->load($composer, [$projectFile]);
    expect($declarations->isSuccessful)->toBeTrue();

    return [$composer, $declarations];
}

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
    public string $label {
        get { throw new \LogicException('hook body must not survive'); }
        set { throw new \LogicException('hook body must not survive'); }
    }

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
        )))->not->toContain('must not survive', 'hook body must not survive', 'throw new')
        ->and(implode("\n", array_map(
            static fn (string $path): string => file_get_contents($path) ?: '',
            glob($first . '/packages/*.json') ?: [],
        )))->toContain('get {', 'set {');

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

test('portable dependency index replacement removes stale shards', function (): void {
    $root = $this->createTemporaryDirectory();
    (new NativeBuildFilesystem())->cloneTree(
        dirname(__DIR__, 3) . '/tests/Fixtures/DependencyIndex/project',
        $root,
    );
    [$composer, $declarations] = loadStageThirteenDPortableIndexInputs($root);
    $output = $root . '/ppphp-dependencies';
    $writer = new DependencyDeclarationIndexWriter();
    $writer->write($composer, $declarations, '8.4', $output);
    $this->writeFile($output . '/packages/stale.json', '{}');
    $writer->write($composer, $declarations, '8.4', $output);

    expect(file_exists($output . '/packages/stale.json'))->toBeFalse()
        ->and((new DependencyDeclarationIndexReader())->read($output . '/manifest.json', '8.4')->isSuccessful)
        ->toBeTrue();
});

test('portable dependency index failures preserve a verified generation', function (
    string $phase,
    bool $candidateCommits,
    bool $orphanPreserved,
): void {
    $root = $this->createTemporaryDirectory();
    (new NativeBuildFilesystem())->cloneTree(
        dirname(__DIR__, 3) . '/tests/Fixtures/DependencyIndex/project',
        $root,
    );
    [$composer, $declarations] = loadStageThirteenDPortableIndexInputs($root);
    $output = $root . '/ppphp-dependencies';
    (new DependencyDeclarationIndexWriter())->write($composer, $declarations, '8.4', $output);
    $priorManifest = file_get_contents($output . '/manifest.json');
    expect($priorManifest)->toBeString();
    $this->writeFile(
        $root . '/vendor/acme/portable/functions.php',
        "<?php\nfunction acme_portable(string \$value): int { return strlen(\$value); }\n",
    );
    [$changedComposer, $changedDeclarations] = loadStageThirteenDPortableIndexInputs($root);
    $filesystem = new class($phase) extends NativeBuildFilesystem {
        private int $moves = 0;
        private bool $injected = false;

        public function __construct(private readonly string $phase) {}

        public function writeFile(string $path, string $contents, ?int $mode = null): void
        {
            if (!$this->injected
                && str_contains($path, '.candidate-')
                && $this->phase === 'shard-write'
                && str_contains($path, '/packages/')) {
                $this->injected = true;
                throw new RuntimeException('Injected shard write failure.');
            }

            if (!$this->injected
                && str_contains($path, '.candidate-')
                && str_ends_with($path, '/manifest.json')) {
                if ($this->phase === 'manifest-write') {
                    $this->injected = true;
                    throw new RuntimeException('Injected manifest write failure.');
                }

                if ($this->phase === 'candidate-verification') {
                    $this->injected = true;
                    parent::writeFile($path, '{}', $mode);
                    return;
                }
            }

            parent::writeFile($path, $contents, $mode);
        }

        public function move(string $from, string $to): void
        {
            $this->moves++;

            if (!$this->injected
                && (($this->phase === 'backup-move' && $this->moves === 1)
                    || ($this->phase === 'candidate-move' && $this->moves === 2))) {
                $this->injected = true;
                throw new RuntimeException('Injected dependency index move failure.');
            }

            parent::move($from, $to);

            if (!$this->injected && $this->phase === 'committed-verification' && $this->moves === 2) {
                $this->injected = true;
                parent::writeFile($to . '/manifest.json', '{}', 0600);
            }
        }

        public function remove(string $path): void
        {
            if (!$this->injected
                && in_array($this->phase, ['backup-cleanup', 'ambiguous-backup-cleanup'], true)
                && str_contains($path, '.backup-')) {
                $this->injected = true;

                if ($this->phase === 'ambiguous-backup-cleanup') {
                    parent::remove($path . '/.ppphp-index-transaction.json');
                }

                throw new RuntimeException('Injected dependency index backup cleanup failure.');
            }

            parent::remove($path);
        }
    };
    $writer = new DependencyDeclarationIndexWriter(filesystem: $filesystem);

    expect(fn () => $writer->write($changedComposer, $changedDeclarations, '8.4', $output))
        ->toThrow(RuntimeException::class);

    $currentManifest = file_get_contents($output . '/manifest.json');
    expect($currentManifest)->toBeString()
        ->and($candidateCommits ? $currentManifest !== $priorManifest : $currentManifest === $priorManifest)->toBeTrue()
        ->and((new DependencyDeclarationIndexReader())->read($output . '/manifest.json', '8.4')->isSuccessful)
        ->toBeTrue()
        ->and(glob($root . '/.ppphp-dependencies.candidate-*') ?: [])->toBe([])
        ->and(count(glob($root . '/.ppphp-dependencies.backup-*') ?: []))->toBe($orphanPreserved ? 1 : 0);
})->with([
    'shard write' => ['shard-write', false, false],
    'before manifest' => ['manifest-write', false, false],
    'candidate verification' => ['candidate-verification', false, false],
    'output to backup move' => ['backup-move', false, false],
    'candidate to output move' => ['candidate-move', false, false],
    'committed verification' => ['committed-verification', false, false],
    'backup cleanup' => ['backup-cleanup', true, false],
    'ambiguous backup cleanup' => ['ambiguous-backup-cleanup', true, true],
]);

test('portable declaration validation independently rejects every executable body boundary', function (string $source): void {
    expect(fn () => (new PortableDeclarationValidator())->validateSource($source))
        ->toThrow(RuntimeException::class);
})->with([
    'function' => '<?php function work(): void { echo 1; }',
    'method' => '<?php class Work { public function run(): void { echo 1; } }',
    'constructor' => '<?php class Work { public function __construct() { echo 1; } }',
    'destructor' => '<?php class Work { public function __destruct() { echo 1; } }',
    'property hook' => '<?php class Work { public string $value { get { return "x"; } } }',
    'top-level' => '<?php echo 1;',
    'closure default' => '<?php const WORK = null; function work(mixed $value = null): void {} $closure = fn () => 1;',
]);

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
            'compatibilityIdentity' => DeclarationCompatibilityIdentity::calculate(),
            'declarationFormatVersion' => DependencyDeclarationIndexWriter::DECLARATION_FORMAT_VERSION,
            'packages' => $manifest['packages'],
            'producer' => $manifest['producer'],
            'targetPhpVersion' => '8.4',
        ]);
        $manifest['contentIdentity'] = 'sha256:' . hash('sha256', $identity);
        file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    };

    match ($case) {
        'format' => $manifest['formatVersion'] = 999,
        'declaration-format' => $manifest['declarationFormatVersion'] = 999,
        'target' => $manifest['targetPhpVersion'] = '8.3',
        'producer' => $manifest['producer']['identity'] = 'incompatible/producer',
        'manifest-hash' => $expectedHash = str_repeat('0', 64),
        'shard-hash' => file_put_contents($shardPath, CanonicalJson::encode($shard) . " \n"),
        'missing-shard' => unlink($shardPath),
        'duplicate-package' => $manifest['packages'][] = $manifest['packages'][0],
        'invalid-type' => $shard['documents'][0]['source'] = "<?php\nfunction invalid(array< \$value): void {}\n",
        'absolute-path' => $shard['documents'][0]['path'] = '/absolute.php',
        'traversal' => $shard['documents'][0]['path'] = '../escape.php',
        'count' => $shard['counts']['functions']++,
        'duplicate-declaration' => $shard['documents'][] = $shard['documents'][0],
        'document-order' => $shard['documents'][1]['order'] = -1,
        'alias-cycle' => $shard['aliases'] = [
            'Acme\\First' => ['autoloadForm' => 'files', 'order' => 0, 'original' => 'Acme\\Second', 'path' => 'functions.php'],
            'Acme\\Second' => ['autoloadForm' => 'files', 'order' => 0, 'original' => 'Acme\\First', 'path' => 'functions.php'],
        ],
        'unknown-alias-property' => $shard['aliases']['Acme\\AliasService']['unknown'] = true,
        'size' => $shard['documents'][0]['source'] = "<?php\n/*" . str_repeat('x', ComposerDependencyDeclarationLoader::MAXIMUM_BYTES) . "*/\n",
    };

    if (in_array($case, ['format', 'declaration-format', 'target', 'producer', 'duplicate-package'], true)) {
        file_put_contents($manifestPath, CanonicalJson::encode($manifest));
    } elseif (in_array($case, [
        'invalid-type',
        'absolute-path',
        'traversal',
        'count',
        'duplicate-declaration',
        'document-order',
        'alias-cycle',
        'unknown-alias-property',
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
    'wrong producer' => 'producer',
    'manifest hash mismatch' => 'manifest-hash',
    'shard hash mismatch' => 'shard-hash',
    'missing shard' => 'missing-shard',
    'duplicate package' => 'duplicate-package',
    'invalid serialized type' => 'invalid-type',
    'absolute document path' => 'absolute-path',
    'traversing document path' => 'traversal',
    'count mismatch' => 'count',
    'duplicate declaration' => 'duplicate-declaration',
    'document order' => 'document-order',
    'alias cycle' => 'alias-cycle',
    'unknown alias property' => 'unknown-alias-property',
    'size limit' => 'size',
]);
