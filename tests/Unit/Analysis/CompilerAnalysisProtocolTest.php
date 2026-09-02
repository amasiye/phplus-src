<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisProtocol;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequest;
use Amasiye\Ppphp\Analysis\Browser\CompilerAnalysisRequestDecoder;
use Amasiye\Ppphp\Analysis\Capability\AnalysisCapabilityCatalog;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Amasiye\Ppphp\Interop\Composer\ComposerResolver;
use Amasiye\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexWriter;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;

function writeCompilerAnalysisProject(string $root, string $source): void
{
    mkdir($root . '/src', 0777, true);
    file_put_contents($root . '/ppphp.json', json_encode([
        'source' => ['src'],
        'output' => 'build/ppphp',
        'cache' => '.ppphp-cache',
        'targetPhpVersion' => '8.4',
        'stubs' => [],
        'exclude' => ['vendor', 'build', '.ppphp-cache'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($root . '/src/main.ppphp', $source);
}

test('compiler analysis request version two accepts only compiler-owned checks', function (): void {
    $decoder = new CompilerAnalysisRequestDecoder();
    $request = $decoder->decode(json_encode([
        'version' => 2,
        'requestId' => 'compiler-check',
        'action' => 'analyze',
        'operation' => 'check',
        'analysis' => ['engine' => 'compiler'],
        'selection' => ['path' => null],
    ], JSON_THROW_ON_ERROR));

    expect($request->requestId)->toBe('compiler-check')
        ->and($request->path)->toBeNull()
        ->and($request->dependencyContext)->toBeNull()
        ->and(fn () => $decoder->decode(json_encode([
            'version' => 2,
            'requestId' => 'build',
            'action' => 'analyze',
            'operation' => 'build',
            'analysis' => ['engine' => 'compiler'],
            'selection' => ['path' => null],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'supports check only')
        ->and(fn () => $decoder->decode(json_encode([
            'version' => 2,
            'requestId' => 'missing-path',
            'action' => 'analyze',
            'operation' => 'check',
            'analysis' => ['engine' => 'compiler'],
            'selection' => [],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'malformed')
        ->and(fn () => $decoder->decode(str_repeat(' ', CompilerAnalysisRequest::MAXIMUM_TRANSPORT_BYTES + 1)))
        ->toThrow(InvalidArgumentException::class, 'too large');
});

test('compiler analysis request version two validates optional portable dependency context', function (): void {
    $request = (new CompilerAnalysisRequestDecoder())->decode(json_encode([
        'version' => 2,
        'requestId' => 'portable',
        'action' => 'analyze',
        'operation' => 'check',
        'analysis' => ['engine' => 'compiler'],
        'selection' => ['path' => null],
        'dependencyContext' => [
            'kind' => 'portable-index',
            'manifestPath' => 'ppphp-dependencies/manifest.json',
            'sha256' => str_repeat('a', 64),
        ],
    ], JSON_THROW_ON_ERROR));

    expect($request->dependencyContext?->manifestPath)->toBe('ppphp-dependencies/manifest.json')
        ->and(fn () => (new CompilerAnalysisRequestDecoder())->decode(json_encode([
            'version' => 2,
            'requestId' => 'portable',
            'action' => 'analyze',
            'operation' => 'check',
            'analysis' => ['engine' => 'compiler'],
            'selection' => ['path' => null],
            'dependencyContext' => ['kind' => 'remote', 'manifestPath' => 'index', 'sha256' => 'bad'],
        ], JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class, 'dependency context is malformed');
});

test('compiler analysis rejects a portable dependency manifest that escapes through a symlink', function (): void {
    $root = $this->createTemporaryDirectory();
    $outside = $this->createTemporaryDirectory();
    writeCompilerAnalysisProject($root, "<?php\nfunction valid(): void {}\n");
    $this->writeFile($outside . '/manifest.json', "{}\n");
    symlink($outside, $root . '/linked-index');

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest(
            'symlink-escape',
            null,
            new \Amasiye\Ppphp\Analysis\Browser\PortableDependencyContext(
                'linked-index/manifest.json',
                hash('sha256', "{}\n"),
            ),
        ),
        $root,
    )->toArray();

    expect($response['status'])->toBe('error')
        ->and($response['error']['code'])->toBe('invalid-dependency-context')
        ->and($response['error']['limit'])->toBe('dependencyContext.manifestPath');
});

test('compiler analysis resolves dependencies from a source-free portable index', function (): void {
    $root = $this->createTemporaryDirectory();
    writeCompilerAnalysisProject($root, <<<'PHP'
<?php
use Acme\AliasService;
use Acme\Service;
function consume(AliasService<string> $service): string throws \RuntimeException
{
    Service<string> $constructed = new Service('created');
    string $property = $service->name;
    string $method = $service->inherited();
    string $generic = $service->apply('generic');
    array<string> $values = acme_values();
    $service->risky();

    return acme_portable(ACME_PORTABLE . $constructed->name . $property . $method . $generic . $values[0]);
}
PHP);
    $this->writeFile($root . '/composer.json', '{}');
    $this->writeFile($root . '/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/portable',
            'install_path' => '../acme/portable',
            'autoload' => [
                'psr-4' => ['Acme\\' => 'src'],
                'files' => ['functions.php'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
    $this->writeFile($root . '/vendor/acme/portable/functions.php', <<<'PHP'
<?php
const ACME_PORTABLE = 'portable';
function acme_portable(string $value): string { throw new LogicException(); }
/** @return list<string> */
function acme_values(): array { throw new LogicException(); }
class_alias(\Acme\Service::class, \Acme\AliasService::class);
PHP);
    $this->writeFile($root . '/vendor/acme/portable/src/Service.php', <<<'PHP'
<?php
namespace Acme;
class BaseService
{
    public function inherited(): string { throw new \LogicException(); }
}
/** @template T */
final class Service extends BaseService
{
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
    /**
     * @template U
     * @param U $value
     * @return U
     */
    public function apply(mixed $value): mixed { throw new \LogicException(); }
    /** @throws \RuntimeException */
    public function risky(): void { throw new \RuntimeException(); }
}
PHP);
    $source = new SourceFile(
        $root . '/src/main.ppphp',
        'src/main.ppphp',
        FileKind::Ppphp,
        file_get_contents($root . '/src/main.ppphp') ?: '',
    );
    $parsed = (new PpphpParser())->parse($source)->parsedFile;
    $composer = (new ComposerResolver())->resolve($root)->project;
    expect($parsed)->not->toBeNull()->and($composer)->not->toBeNull();
    $declarations = (new ComposerDependencyDeclarationLoader())->load($composer, [$parsed]);
    $manifest = $root . '/ppphp-dependencies/manifest.json';
    (new DependencyDeclarationIndexWriter())->write($composer, $declarations, '8.4', dirname($manifest));
    unlink($root . '/vendor/acme/portable/functions.php');
    unlink($root . '/vendor/acme/portable/src/Service.php');

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest(
            'source-free',
            null,
            new \Amasiye\Ppphp\Analysis\Browser\PortableDependencyContext(
                'ppphp-dependencies/manifest.json',
                hash('sha256', file_get_contents($manifest) ?: ''),
            ),
        ),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and($response['diagnostics']['diagnostics'])->toBe([])
        ->and($response)->not->toHaveKeys(['phpStan', 'continuation', 'command']);
});

test('compiler analysis completes valid and invalid projects without materializing backend state', function (string $source, array $codes): void {
    $root = $this->createTemporaryDirectory();
    writeCompilerAnalysisProject($root, $source);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('compiler-only', null),
        $root,
    )->toArray();
    $actualCodes = array_column($response['diagnostics']['diagnostics'], 'code');
    sort($actualCodes, SORT_STRING);
    sort($codes, SORT_STRING);

    expect($response['status'])->toBe('complete')
        ->and($response['engine'])->toBe('compiler')
        ->and($response['completeness'])->toBe('compilerCore')
        ->and($response['catalogVersion'])->toBe(AnalysisCapabilityCatalog::VERSION)
        ->and($response['fullParity'])->toBeTrue()
        ->and($response['uncoveredRequiredCapabilities'])->toBe([])
        ->and($response['uncoveredRequiredCapabilities'])->toBe((new AnalysisCapabilityCatalog())->uncoveredRequiredCapabilityIds)
        ->and($actualCodes)->toBe($codes)
        ->and($response)->not->toHaveKeys(['phpStan', 'continuation', 'command'])
        ->and(is_dir($root . '/.ppphp-cache/analysis'))->toBeFalse()
        ->and(is_file($root . '/.ppphp-cache/analysis/phpstan.neon'))->toBeFalse()
        ->and(is_file($root . '/.ppphp-cache/analysis/result.json'))->toBeFalse();
})->with([
    'valid source' => ["<?php\nfunction identity(int \$value): int { return \$value; }\n", []],
    'invalid source' => ["<?php\nfunction invalid(): void { int \$value = 'wrong'; }\n", ['P2008']],
    'Stage 13B type flow' => [<<<'PPP'
<?php
function take(int $value): void {}
final class User
{
    public string $name;
    public function wrong(): string { return 1; }
}
function invalid(User $user): void
{
    take('wrong');
    $user->missing();
    $user->name = 1;
    strlen([]);
}
PPP, ['P2015', 'P2015', 'P2016', 'P2018', 'P2024', 'P2044']],
]);

test('compiler analysis enforces source count and total source byte limits', function (string $limit): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => []]);

    if ($limit === 'sourceCount') {
        for ($index = 0; $index <= CompilerAnalysisRequest::MAXIMUM_SOURCE_FILES; $index++) {
            $this->writeFile($root . sprintf('/src/file-%03d.ppphp', $index), "<?php\n");
        }
    } else {
        $this->writeFile(
            $root . '/src/main.ppphp',
            "<?php\n/*" . str_repeat('x', CompilerAnalysisRequest::MAXIMUM_SOURCE_BYTES) . "*/\n",
        );
    }

    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('resource-limit', null),
        $root,
    )->toArray();
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('error')
        ->and($response['error']['code'])->toBe('resource-limit-exceeded')
        ->and($response['error']['limit'])->toBe($limit)
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
})->with(['sourceCount', 'sourceBytes']);

test('compiler analysis rejects excessive diagnostics without truncating its JSON response', function (): void {
    $root = $this->createTemporaryDirectory();
    $source = "<?php\nfunction invalid(): void {\n";

    for ($index = 0; $index <= CompilerAnalysisRequest::MAXIMUM_DIAGNOSTICS; $index++) {
        $source .= sprintf("\$missing%d = %d;\n", $index, $index);
    }

    $source .= "}\n";
    writeCompilerAnalysisProject($root, $source);
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('diagnostic-limit', null),
        $root,
    )->toArray();
    $json = json_encode($response, JSON_THROW_ON_ERROR);

    expect($response['status'])->toBe('error')
        ->and($response['error']['limit'])->toBe('diagnosticCount')
        ->and(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe($response);
});

test('compiler analysis parses user code as data and never executes it', function (): void {
    $root = $this->createTemporaryDirectory();
    $marker = $root . '/executed.txt';
    writeCompilerAnalysisProject($root, sprintf(
        "<?php\nfile_put_contents(%s, 'executed');\n",
        var_export($marker, true),
    ));
    $response = (new CompilerAnalysisProtocol())->analyze(
        new CompilerAnalysisRequest('no-execution', null),
        $root,
    )->toArray();

    expect($response['status'])->toBe('complete')
        ->and(file_exists($marker))->toBeFalse();
});
