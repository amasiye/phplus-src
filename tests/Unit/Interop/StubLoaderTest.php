<?php

declare(strict_types=1);

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Interop\Stub\StubLoader;
use Amasiye\Phplus\Source\Enumerations\FileKind;

test('stub loading is recursive deterministic filtered and does not follow directory symlinks', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $outside = $container . '/outside';
    $this->writeFile($root . '/stubs/Nested/B.stub.php', '<?php');
    $this->writeFile($root . '/stubs/A.stub.php', '<?php');
    $this->writeFile($root . '/stubs/ignored.php', '<?php');
    $this->writeFile($outside . '/Outside.stub.php', '<?php');
    symlink($outside, $root . '/stubs/Linked');
    $root = (string) realpath($root);
    $configuration = new ProjectConfig(
        $root,
        $root . '/phplus.json',
        [$root . '/src'],
        $root . '/build/phplus',
        $root . '/.phplus-cache',
        '8.4',
        [$root . '/stubs'],
        [],
    );

    $result = (new StubLoader())->load($configuration);
    $files = $result->repository?->files ?? [];

    expect($result->isSuccessful)->toBeTrue()
        ->and(array_map(static fn ($file): string => $file->path, $files))->toBe([
            $root . '/stubs/A.stub.php',
            $root . '/stubs/Nested/B.stub.php',
        ])->and($files[0]->kind)->toBe(FileKind::Stub);
});
