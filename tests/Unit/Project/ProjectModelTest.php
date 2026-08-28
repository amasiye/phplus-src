<?php

declare(strict_types=1);

use Amasiye\Phplus\Project\DependencyGraph;
use Amasiye\Phplus\Project\FileDiscovery;
use Amasiye\Phplus\Project\ProjectSource;
use Amasiye\Phplus\Project\SourceSet;
use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Source\Enumerations\FileKind;

test('source sets are deterministic path-keyed and filter by subtree and kind', function (): void {
    $sources = new SourceSet([
        new ProjectSource('/project/src/Z.php', '/project/src', FileKind::Php),
        new ProjectSource('/project/src/A.phplus', '/project/src', FileKind::Phplus),
        new ProjectSource('/project/src/Nested/B.phplus', '/project/src', FileKind::Phplus),
    ]);

    expect(array_map(static fn (ProjectSource $source): string => $source->path, $sources->files()))->toBe([
        '/project/src/A.phplus',
        '/project/src/Nested/B.phplus',
        '/project/src/Z.php',
    ])->and(count($sources->ofKind(FileKind::Phplus)))->toBe(2)
        ->and(count($sources->beneath('/project/src/Nested')))->toBe(1);
});

test('the dependency graph is path keyed cycle tolerant and exposes both directions', function (): void {
    $graph = new DependencyGraph();
    $graph->addDependency('/project/A.php', '/project/B.php');
    $graph->addDependency('/project/B.php', '/project/A.php');

    expect($graph->nodes())->toBe(['/project/A.php', '/project/B.php'])
        ->and($graph->dependenciesOf('/project/A.php'))->toBe(['/project/B.php'])
        ->and($graph->dependentsOf('/project/A.php'))->toBe(['/project/B.php']);
});

test('discovery assigns most-specific ownership and safely deduplicates aliases', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeFile($root . '/src/Z.php', '<?php');
    $this->writeFile($root . '/src/Nested/A.phplus', '<?php');
    $this->writeFile($root . '/src/Excluded/Broken.phplus', '<?php');
    $this->writeFile($root . '/src/Stubs/library.stub.php', '<?php');
    $this->writeFile($root . '/src/ignored.txt', 'ignored');
    symlink($root . '/src/Z.php', $root . '/src/Alias.phplus');
    symlink($root . '/src', $root . '/src/Loop');
    $root = (string) realpath($root);
    $configuration = new ProjectConfig(
        $root,
        $root . '/phplus.json',
        [$root . '/src', $root . '/src/Nested'],
        $root . '/build/phplus',
        $root . '/.phplus-cache',
        '8.4',
        [$root . '/src/Stubs'],
        [$root . '/src/Excluded'],
    );

    $result = (new FileDiscovery())->discover($configuration);
    $files = $result->sources?->files() ?? [];

    expect($result->isSuccessful())->toBeTrue()
        ->and(array_map(static fn (ProjectSource $source): string => $source->displayPath, $files))->toBe([
            'src/Nested/A.phplus',
            'src/Z.php',
        ])->and($files[0]->sourceRoot)->toBe($root . '/src/Nested')
        ->and($files[0]->relativePath)->toBe('A.phplus')
        ->and($files[1]->kind)->toBe(FileKind::Php);
});
