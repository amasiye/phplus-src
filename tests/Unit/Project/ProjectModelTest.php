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
        new ProjectSource('/project/src/A.ppp', '/project/src', FileKind::Ppp),
        new ProjectSource('/project/src/Nested/B.ppp', '/project/src', FileKind::Ppp),
    ]);

    expect(array_map(static fn (ProjectSource $source): string => $source->path, $sources->files))->toBe([
        '/project/src/A.ppp',
        '/project/src/Nested/B.ppp',
        '/project/src/Z.php',
    ])->and(count($sources->filterByKind(FileKind::Ppp)))->toBe(2)
        ->and(count($sources->filterBeneath('/project/src/Nested')))->toBe(1);
});

test('the dependency graph is path keyed cycle tolerant and exposes both directions', function (): void {
    $graph = new DependencyGraph();
    $graph->addDependency('/project/A.php', '/project/B.php');
    $graph->addDependency('/project/B.php', '/project/A.php');

    expect($graph->nodes)->toBe(['/project/A.php', '/project/B.php'])
        ->and($graph->findDependenciesOf('/project/A.php'))->toBe(['/project/B.php'])
        ->and($graph->findDependentsOf('/project/A.php'))->toBe(['/project/B.php']);
});

test('discovery assigns most-specific ownership and safely deduplicates aliases', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/src/Z.php', '<?php');
    $this->writeFile($root . '/src/Nested/A.ppp', '<?php');
    $this->writeFile($root . '/src/Excluded/Broken.ppp', '<?php');
    $this->writeFile($root . '/src/Stubs/library.stub.php', '<?php');
    $this->writeFile($root . '/src/ignored.txt', 'ignored');
    symlink($root . '/src/Z.php', $root . '/src/Alias.ppp');
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
    $files = $result->sources?->files ?? [];

    expect($result->isSuccessful)->toBeTrue()
        ->and(array_map(static fn (ProjectSource $source): string => $source->displayPath, $files))->toBe([
            'src/Nested/A.ppp',
            'src/Z.php',
        ])->and($files[0]->sourceRoot)->toBe($root . '/src/Nested')
        ->and($files[0]->relativePath)->toBe('A.ppp')
        ->and($files[1]->kind)->toBe(FileKind::Php);
});
