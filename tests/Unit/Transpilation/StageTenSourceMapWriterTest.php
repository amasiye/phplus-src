<?php

declare(strict_types=1);

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMapSegment;
use Amasiye\Ppphp\Transpilation\SourceMapWriter;

function stageTenSourceMapArtifact(?GeneratedSourceMap $map = null, string $displayPath = 'src\\Plain.php'): CompilationArtifact
{
    $contents = "<?php\n";
    $source = new SourceFile('/project/src/Plain.php', $displayPath, FileKind::Php, $contents);
    $projectSource = new ProjectSource('/project/src/Plain.php', '/project/src', FileKind::Php, '/project');

    return new CompilationArtifact(
        $projectSource,
        $source,
        OutputOperation::Copy,
        '/project/build/ppphp/Plain.php',
        'Plain.php',
        $contents,
        $map ?? GeneratedSourceMap::createIdentity($source),
        'sha256:' . str_repeat('a', 64),
        'sha256:' . str_repeat('b', 64),
        0644,
    );
}

test('identity source maps serialize canonically and match their golden fixture', function (): void {
    $writer = new SourceMapWriter();
    $artifact = stageTenSourceMapArtifact();
    $serialized = $writer->serialize($artifact);
    $golden = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Build/source-map.json');

    expect($serialized)->toBe($golden)
        ->toEndWith("\n")
        ->not->toContain('\\')
        ->not->toContain('/project/');

    $parsed = $writer->parseAndValidate(
        $serialized,
        'src/Plain.php',
        'Plain.php',
        $artifact->sourceHash,
        $artifact->outputHash,
        strlen($artifact->contents),
    );

    expect($parsed['segments'])->toHaveCount(1)
        ->and($parsed['segments'][0])->toMatchArray([
            'generatedStart' => 0,
            'generatedEnd' => 6,
            'originalStart' => 0,
            'originalEnd' => 6,
        ]);
});

test('source map serialization rejects invalid generated ranges lengths and absolute source identities', function (): void {
    $artifact = stageTenSourceMapArtifact();
    $source = $artifact->sourceFile;
    $badRange = new GeneratedSourceMap($source, 6, [new GeneratedSourceMapSegment(0, 7, 0, 6)]);
    $badLength = new GeneratedSourceMap($source, 5, [new GeneratedSourceMapSegment(0, 5, 0, 5)]);
    $writer = new SourceMapWriter();

    expect(fn (): string => $writer->serialize(stageTenSourceMapArtifact($badRange)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): CompilationArtifact => stageTenSourceMapArtifact($badLength))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $writer->serialize(stageTenSourceMapArtifact(displayPath: '/project/src/Plain.php')))
        ->toThrow(InvalidArgumentException::class);
});

test('persisted source map validation rejects generated identity and segment corruption', function (): void {
    $writer = new SourceMapWriter();
    $artifact = stageTenSourceMapArtifact();
    $data = json_decode($writer->serialize($artifact), true, 512, JSON_THROW_ON_ERROR);
    $data['segments'][0]['generatedEnd'] = 7;
    $json = json_encode($data, JSON_THROW_ON_ERROR);

    expect(fn (): array => $writer->parseAndValidate(
        $json,
        'src/Plain.php',
        'Plain.php',
        $artifact->sourceHash,
        $artifact->outputHash,
        6,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => $writer->parseAndValidate(
            $writer->serialize($artifact),
            'src/Plain.php',
            'Other.php',
            $artifact->sourceHash,
            $artifact->outputHash,
            6,
        ))->toThrow(InvalidArgumentException::class);
});
