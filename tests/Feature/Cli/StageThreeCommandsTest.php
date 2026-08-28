<?php

declare(strict_types=1);

use Amasiye\Phplus\Cli\Application;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageThreeCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('pathless check and build operate on the complete mixed project source set', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/App.php', '<?php final class App {}');
    $this->writeFile($root . '/src/Domain/Person.phplus', '<?php final class Person {}');
    $this->writeFile($root . '/src/index.phplus', '<?php echo "hello";');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('Checked 3 Files: 2 PHPlus, 1 PHP.')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Built 2 PHPlus Files.')
        ->and(file_get_contents($root . '/build/phplus/Domain/Person.php'))->toBe('<?php final class Person {}')
        ->and(file_get_contents($root . '/build/phplus/index.php'))->toBe('<?php echo "hello";')
        ->and(file_exists($root . '/build/phplus/App.php'))->toBeFalse();
});

test('directory selection is recursive and does not validate or emit an unselected subtree', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected/One.phplus', '<?php echo 1;');
    $this->writeFile($root . '/src/Selected/Nested/Context.php', '<?php final class Context {}');
    $this->writeFile($root . '/src/Other/Broken.phplus', '<?php echo ;');

    $build = runStageThreeCommand([
        'command' => 'build',
        'path' => 'src/Selected',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Built 1 PHPlus Files.')
        ->and(file_exists($root . '/build/phplus/Selected/One.php'))->toBeTrue()
        ->and(file_exists($root . '/build/phplus/Other/Broken.php'))->toBeFalse();
});

test('focused file checking ignores unselected source syntax errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.phplus', '<?php echo 1;');
    $this->writeFile($root . '/src/Broken.php', '<?php echo ;');

    $focused = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.phplus',
        '--working-directory' => $root,
    ]);
    $complete = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($focused->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($complete->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($complete->getDisplay())->toContain('src/Broken.php:');
});

test('a project build parses every selected file before writing any output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/AValid.phplus', '<?php echo 1;');
    $this->writeFile($root . '/src/ZBroken.phplus', '<?php echo ;');

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/phplus/AValid.php'))->toBeFalse()
        ->and(file_exists($root . '/build/phplus/ZBroken.php'))->toBeFalse();
});

test('ordinary PHP is checked as project context but is never a direct build target', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Context.php', '<?php final class Context {}');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Context.php',
        '--working-directory' => $root,
    ]);
    $build = runStageThreeCommand([
        'command' => 'build',
        'path' => 'src/Context.php',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($build->getDisplay())->toContain('Error[P1007]: PHP Source Is Not A Build Target');
});

test('output collisions block only builds whose selected emission participates', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['one', 'two', 'three']]);
    $this->writeFile($root . '/one/Same.phplus', '<?php echo 1;');
    $this->writeFile($root . '/two/Same.phplus', '<?php echo 2;');
    $this->writeFile($root . '/three/Other.phplus', '<?php echo 3;');

    $colliding = runStageThreeCommand([
        'command' => 'build',
        'path' => 'one/Same.phplus',
        '--working-directory' => $root,
    ]);
    $unrelated = runStageThreeCommand([
        'command' => 'build',
        'path' => 'three/Other.phplus',
        '--working-directory' => $root,
    ]);

    expect($colliding->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($colliding->getDisplay())->toContain('Error[P7002]: Generated PHP Output Path Collision')
        ->and($unrelated->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/phplus/Other.php'))->toBeTrue();
});

test('excluded source subtrees and directory symlinks are not discovered', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $outside = $container . '/outside';
    $this->writeConfiguration($root, ['exclude' => ['src/Excluded']]);
    $this->writeFile($root . '/src/Included.phplus', '<?php echo 1;');
    $this->writeFile($root . '/src/Excluded/Broken.phplus', '<?php echo ;');
    $this->writeFile($outside . '/Linked.phplus', '<?php echo ;');
    symlink($outside, $root . '/src/LinkedDirectory');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('Checked 1 Files: 1 PHPlus, 0 PHP.');
});

test('configured stubs are global syntax context for focused commands', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.phplus', '<?php echo 1;');
    $this->writeFile($root . '/stubs/Broken.stub.php', '<?php function broken(');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.phplus',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('stubs/Broken.stub.php:');
});

test('dump ast accepts an ordinary project-owned PHP file', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Context.php', '<?php final class Context {}');

    $dump = runStageThreeCommand([
        'command' => 'dump:ast',
        'path' => 'src/Context.php',
        '--working-directory' => $root,
    ]);

    expect($dump->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dump->getDisplay())->toContain('Stmt_Class');
});

test('source discovery handles supported extensions case-insensitively', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Context.PHP', '<?php final class Context {}');
    $this->writeFile($root . '/src/Feature.PHPLUS', '<?php echo 1;');

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root . '/build/phplus/Feature.php'))->toBe('<?php echo 1;');
});

test('selection rejects missing unsupported excluded and non-owned paths', function (string $path, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['exclude' => ['src/Excluded']]);
    $this->writeFile($root . '/src/Excluded/Hidden.phplus', '<?php');
    $this->writeFile($root . '/src/readme.txt', 'text');
    $this->writeFile($root . '/other/Outside.phplus', '<?php');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => $path,
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($check->getDisplay())->toContain('Error[' . $code . ']');
})->with([
    'missing path' => ['src/Missing.phplus', 'P0018'],
    'unsupported file' => ['src/readme.txt', 'P1004'],
    'excluded file' => ['src/Excluded/Hidden.phplus', 'P0024'],
    'outside source roots' => ['other/Outside.phplus', 'P1005'],
    'project root is not a selection root' => ['.', 'P1005'],
]);

test('empty source roots and directories containing only PHP are valid selections', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');

    $emptyCheck = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $this->writeFile($root . '/src/PhpOnly/Context.php', '<?php final class Context {}');
    $phpBuild = runStageThreeCommand([
        'command' => 'build',
        'path' => 'src/PhpOnly',
        '--working-directory' => $root,
    ]);

    expect($emptyCheck->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($emptyCheck->getDisplay())->toContain('Checked 0 Files: 0 PHPlus, 0 PHP.')
        ->and($phpBuild->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($phpBuild->getDisplay())->toContain('Built 0 PHPlus Files.')
        ->and(file_exists($root . '/build/phplus/PhpOnly/Context.php'))->toBeFalse();
});

test('syntax diagnostics aggregate in deterministic source order', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/ZBroken.php', '<?php echo ;');
    $this->writeFile($root . '/src/ABroken.phplus', '<?php echo ;');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($check->getDisplay(), 'Error[P1001]'))->toBe(2)
        ->and(strpos($check->getDisplay(), 'src/ABroken.phplus:'))->toBeLessThan(
            strpos($check->getDisplay(), 'src/ZBroken.php:'),
        );
});

test('a missing configured stub directory is invalid project context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => ['missing-stubs']]);
    $this->writeFile($root . '/src/Selected.phplus', '<?php echo 1;');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.phplus',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($check->getDisplay())->toContain('Error[P6004]: Configured Stub Path Is Invalid');
});

test('mixed PHP and generated PHPlus sources run together without rewriting PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $phpBytes = "<?php\nnamespace Demo;\nfinal class PhpMessage { public static function renderText(): string { return 'mixed'; } }\n";
    $this->writeFile($root . '/src/PhpMessage.php', $phpBytes);
    $this->writeFile(
        $root . '/src/GeneratedMessage.phplus',
        "<?php\nnamespace Demo;\nfinal class GeneratedMessage { public static function renderText(): string { return PhpMessage::renderText(); } }\n",
    );
    $this->writeFile(
        $root . '/run.php',
        "<?php\nrequire __DIR__ . '/src/PhpMessage.php';\nrequire __DIR__ . '/build/phplus/GeneratedMessage.php';\necho Demo\\GeneratedMessage::renderText();\n",
    );

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $runtime = new Process([PHP_BINARY, $root . '/run.php']);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($runtime->run())->toBe(0)
        ->and($runtime->getOutput())->toBe('mixed')
        ->and(file_get_contents($root . '/src/PhpMessage.php'))->toBe($phpBytes)
        ->and(file_exists($root . '/build/phplus/PhpMessage.php'))->toBeFalse();
});

test('successful JSON project commands retain the diagnostic envelope', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature.phplus', '<?php echo 1;');

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $json = json_decode($build->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($json['version'])->toBe(1)
        ->and($json['summary']['errors'])->toBe(0)
        ->and($json['diagnostics'])->toBe([]);
});
