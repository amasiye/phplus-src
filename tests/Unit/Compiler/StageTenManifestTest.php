<?php

declare(strict_types=1);

use Amasiye\Ppphp\Compiler\Manifest\BuildManifest;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifestCodec;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifestEntry;
use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Source\Enumerations\FileKind;

function stageTenManifestEntry(
    string $source = 'src/Core/Value.ppphp',
    string $output = 'Core/Value.php',
    FileKind $kind = FileKind::Ppphp,
    OutputOperation $operation = OutputOperation::Compile,
    string $sourceMap = '.ppphp/source-maps/Core/Value.php.map.json',
): BuildManifestEntry {
    return new BuildManifestEntry(
        $source,
        $output,
        $kind,
        $operation,
        'sha256:' . str_repeat('a', 64),
        'sha256:' . str_repeat('b', 64),
        $sourceMap,
        '0755',
    );
}

function stageTenManifest(array $entries): BuildManifest
{
    return new BuildManifest(
        'ppphp',
        'development',
        '8.4',
        'sha256:' . str_repeat('c', 64),
        true,
        $entries,
    );
}

test('manifest serialization is canonical relative deterministic and matches its golden fixture', function (): void {
    $plain = new BuildManifestEntry(
        'src\\bootstrap.php',
        'bootstrap.php',
        FileKind::Php,
        OutputOperation::Copy,
        'sha256:' . str_repeat('d', 64),
        'sha256:' . str_repeat('e', 64),
        '.ppphp\\source-maps\\bootstrap.php.map.json',
        '0644',
    );
    $codec = new BuildManifestCodec();
    $serialized = $codec->serialize(stageTenManifest([stageTenManifestEntry(), $plain]));
    $golden = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Build/manifest.json');

    expect($serialized)->toBe($golden)
        ->and($serialized)->toEndWith("\n")
        ->not->toContain('\\')
        ->not->toContain('createdAt')
        ->not->toContain('/Users/');

    $parsed = $codec->parse($serialized);

    expect($parsed->completeProject)->toBeTrue()
        ->and($parsed->files)->toHaveCount(2)
        ->and($parsed->files[0]->output)->toBe('bootstrap.php')
        ->and($codec->serialize($parsed))->toBe($serialized);
});

test('empty complete and partial manifests round trip', function (bool $complete): void {
    $codec = new BuildManifestCodec();
    $manifest = stageTenManifest([]);
    $manifest = new BuildManifest(
        $manifest->compilerName,
        $manifest->compilerVersion,
        $manifest->targetPhpVersion,
        $manifest->configurationFingerprint,
        $complete,
        [],
    );

    expect($codec->parse($codec->serialize($manifest))->completeProject)->toBe($complete);
})->with([true, false]);

test('manifest parsing rejects malformed and unsafe metadata', function (Closure $mutate): void {
    $codec = new BuildManifestCodec();
    $data = json_decode($codec->serialize(stageTenManifest([stageTenManifestEntry()])), true, 512, JSON_THROW_ON_ERROR);
    $mutate($data);

    expect(fn (): BuildManifest => $codec->parse(json_encode($data, JSON_THROW_ON_ERROR)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unsupported version' => fn (array &$data): mixed => $data['formatVersion'] = 99,
    'absolute source' => fn (array &$data): mixed => $data['files'][0]['source'] = '/private/source.ppphp',
    'output traversal' => fn (array &$data): mixed => $data['files'][0]['output'] = '../Value.php',
    'map traversal' => fn (array &$data): mixed => $data['files'][0]['sourceMap'] = '.ppphp/source-maps/../Value.map.json',
    'reserved output' => fn (array &$data): mixed => $data['files'][0]['output'] = '.ppphp/Value.php',
    'invalid hash' => fn (array &$data): mixed => $data['files'][0]['outputHash'] = 'not-a-hash',
    'mismatched operation' => fn (array &$data): mixed => $data['files'][0]['operation'] = 'copy',
    'mismatched map' => fn (array &$data): mixed => $data['files'][0]['sourceMap'] = '.ppphp/source-maps/Other.php.map.json',
]);

test('manifest parsing rejects invalid JSON and case-normalized duplicate ownership', function (): void {
    $codec = new BuildManifestCodec();

    expect(fn (): BuildManifest => $codec->parse('{'))
        ->toThrow(InvalidArgumentException::class);

    $first = stageTenManifestEntry();
    $duplicateSource = stageTenManifestEntry(
        source: 'SRC/core/value.ppphp',
        output: 'Other.php',
        sourceMap: '.ppphp/source-maps/Other.php.map.json',
    );
    $duplicateOutput = stageTenManifestEntry(
        source: 'src/Other.ppphp',
        output: 'core/value.php',
        sourceMap: '.ppphp/source-maps/core/value.php.map.json',
    );

    expect(fn (): BuildManifest => $codec->parse($codec->serialize(stageTenManifest([$first, $duplicateSource]))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): BuildManifest => $codec->parse($codec->serialize(stageTenManifest([$first, $duplicateOutput]))))
        ->toThrow(InvalidArgumentException::class);
});
