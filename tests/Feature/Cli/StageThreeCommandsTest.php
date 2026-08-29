<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
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
    $this->writeFile($root . '/src/Domain/Person.ppp', '<?php final class Person {}');
    $this->writeFile($root . '/src/index.ppp', '<?php echo "hello";');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('Checked 3 Files: 2 ++PHP, 1 PHP.')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Compiled 2 ++PHP Files.')
        ->and($build->getDisplay())->toContain('Copied 1 PHP File.')
        ->and($build->getDisplay())->toContain('Built 3 Files.')
        ->and(file_get_contents($root . '/build/ppphp/Domain/Person.php'))->toBe('<?php final class Person {}')
        ->and(file_get_contents($root . '/build/ppphp/index.php'))->toBe('<?php echo "hello";')
        ->and(file_get_contents($root . '/build/ppphp/App.php'))->toBe('<?php final class App {}');
});

test('directory selection is recursive and does not validate or emit an unselected subtree', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected/One.ppp', '<?php echo 1;');
    $this->writeFile($root . '/src/Selected/Nested/Context.php', '<?php final class Context {}');
    $this->writeFile($root . '/src/Other/Broken.ppp', '<?php echo ;');

    $build = runStageThreeCommand([
        'command' => 'build',
        'path' => 'src/Selected',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Compiled 1 ++PHP File.')
        ->and($build->getDisplay())->toContain('Copied 1 PHP File.')
        ->and(file_exists($root . '/build/ppphp/Selected/One.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Selected/Nested/Context.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/Other/Broken.php'))->toBeFalse();
});

test('focused file checking ignores unselected source syntax errors', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.ppp', '<?php echo 1;');
    $this->writeFile($root . '/src/Broken.php', '<?php echo ;');

    $focused = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.ppp',
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
    $this->writeFile($root . '/src/AValid.ppp', '<?php echo 1;');
    $this->writeFile($root . '/src/ZBroken.ppp', '<?php echo ;');

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/AValid.php'))->toBeFalse()
        ->and(file_exists($root . '/build/ppphp/ZBroken.php'))->toBeFalse();
});

test('ordinary PHP is checked and copied byte-for-byte as a direct build target', function (): void {
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
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getDisplay())->toContain('Copied 1 PHP File.')
        ->and(file_get_contents($root . '/build/ppphp/Context.php'))->toBe('<?php final class Context {}');
});

test('output collisions block only builds whose selected emission participates', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['one', 'two', 'three']]);
    $this->writeFile($root . '/one/Same.ppp', '<?php echo 1;');
    $this->writeFile($root . '/two/Same.ppp', '<?php echo 2;');
    $this->writeFile($root . '/three/Other.ppp', '<?php echo 3;');

    $colliding = runStageThreeCommand([
        'command' => 'build',
        'path' => 'one/Same.ppp',
        '--working-directory' => $root,
    ]);
    $unrelated = runStageThreeCommand([
        'command' => 'build',
        'path' => 'three/Other.ppp',
        '--working-directory' => $root,
    ]);

    expect($colliding->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($colliding->getDisplay())->toContain('Error[P7002]: Generated PHP Output Path Collision')
        ->and($unrelated->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Other.php'))->toBeTrue();
});

test('excluded source subtrees and directory symlinks are not discovered', function (): void {
    $container = $this->createTemporaryDirectory();
    $root = $container . '/project';
    $outside = $container . '/outside';
    $this->writeConfiguration($root, ['exclude' => ['src/Excluded']]);
    $this->writeFile($root . '/src/Included.ppp', '<?php echo 1;');
    $this->writeFile($root . '/src/Excluded/Broken.ppp', '<?php echo ;');
    $this->writeFile($outside . '/Linked.ppp', '<?php echo ;');
    symlink($outside, $root . '/src/LinkedDirectory');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('Checked 1 Files: 1 ++PHP, 0 PHP.');
});

test('configured stubs are global syntax context for focused commands', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Selected.ppp', '<?php echo 1;');
    $this->writeFile($root . '/stubs/Broken.stub.php', '<?php function broken(');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.ppp',
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
    $this->writeFile($root . '/src/Feature.PPP', '<?php echo 1;');

    $build = runStageThreeCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root . '/build/ppphp/Feature.php'))->toBe('<?php echo 1;');
});

test('selection rejects missing unsupported excluded and non-owned paths', function (string $path, string $code): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['exclude' => ['src/Excluded']]);
    $this->writeFile($root . '/src/Excluded/Hidden.ppp', '<?php');
    $this->writeFile($root . '/src/readme.txt', 'text');
    $this->writeFile($root . '/other/Outside.ppp', '<?php');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => $path,
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($check->getDisplay())->toContain('Error[' . $code . ']');
})->with([
    'missing path' => ['src/Missing.ppp', 'P0018'],
    'unsupported file' => ['src/readme.txt', 'P1004'],
    'excluded file' => ['src/Excluded/Hidden.ppp', 'P0024'],
    'outside source roots' => ['other/Outside.ppp', 'P1005'],
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
        ->and($emptyCheck->getDisplay())->toContain('Checked 0 Files: 0 ++PHP, 0 PHP.')
        ->and($phpBuild->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($phpBuild->getDisplay())->toContain('Compiled 0 ++PHP Files.')
        ->and($phpBuild->getDisplay())->toContain('Copied 1 PHP File.')
        ->and(file_exists($root . '/build/ppphp/PhpOnly/Context.php'))->toBeTrue();
});

test('syntax diagnostics aggregate in deterministic source order', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/ZBroken.php', '<?php echo ;');
    $this->writeFile($root . '/src/ABroken.ppp', '<?php echo ;');

    $check = runStageThreeCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($check->getDisplay(), 'Error[P1001]'))->toBe(2)
        ->and(strpos($check->getDisplay(), 'src/ABroken.ppp:'))->toBeLessThan(
            strpos($check->getDisplay(), 'src/ZBroken.php:'),
        );
});

test('a missing configured stub directory is invalid project context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['stubs' => ['missing-stubs']]);
    $this->writeFile($root . '/src/Selected.ppp', '<?php echo 1;');

    $check = runStageThreeCommand([
        'command' => 'check',
        'path' => 'src/Selected.ppp',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($check->getDisplay())->toContain('Error[P6004]: Configured Stub Path Is Invalid');
});

test('mixed PHP and generated ++PHP sources run together without rewriting PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $phpBytes = "<?php\nnamespace Demo;\nfinal class PhpMessage { public static function renderText(): string { return 'mixed'; } }\n";
    $this->writeFile($root . '/src/PhpMessage.php', $phpBytes);
    $this->writeFile(
        $root . '/src/GeneratedMessage.ppp',
        "<?php\nnamespace Demo;\nfinal class GeneratedMessage { public static function renderText(): string { return PhpMessage::renderText(); } }\n",
    );
    $this->writeFile(
        $root . '/run.php',
        "<?php\nrequire __DIR__ . '/src/PhpMessage.php';\nrequire __DIR__ . '/build/ppphp/GeneratedMessage.php';\necho Demo\\GeneratedMessage::renderText();\n",
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
        ->and(file_get_contents($root . '/build/ppphp/PhpMessage.php'))->toBe($phpBytes);
});

test('compiled and copied sources participate in the same output collision model', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Service.ppp', '<?php final class Service {}');
    $this->writeFile($root . '/src/Service.php', '<?php final class LegacyService {}');

    $build = runStageThreeCommand([
        'command' => 'build',
        'path' => 'src/Service.php',
        '--working-directory' => $root,
    ]);

    expect($build->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($build->getDisplay())->toContain('Error[P7002]: Generated PHP Output Path Collision')
        ->and(file_exists($root . '/build/ppphp/Service.php'))->toBeFalse();
});

test('successful JSON project commands retain the diagnostic envelope', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature.ppp', '<?php echo 1;');

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
