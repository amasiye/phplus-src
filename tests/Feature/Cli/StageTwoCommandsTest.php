<?php

declare(strict_types=1);

use Amasiye\Phplus\Cli\Application;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageTwoCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('check succeeds for one focused ordinary PHP source without emitting PHP', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Example.phplus', "<?php\n// retained\necho 'valid';\n");
    $tester = runStageTwoCommand([
        'command' => 'check',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Checked 1 Files: 1 PHPlus, 0 PHP.')
        ->and(file_exists($root . '/build/phplus/Example.php'))->toBeFalse();
});

test('check maps syntax failures to the original source and emits no PHP', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.phplus', "<?php\nreturn 'missing'\n");
    $tester = runStageTwoCommand([
        'command' => 'check',
        'path' => 'src/Invalid.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P1001]: Invalid PHP Syntax')
        ->and($tester->getDisplay())->toContain('src/Invalid.phplus:')
        ->and($tester->getDisplay())->not->toContain('build/phplus')
        ->and(file_exists($root . '/build/phplus/Invalid.php'))->toBeFalse();
});

test('focused checking accepts files directories and the complete project while retaining path boundaries', function (?string $file, int $status, ?string $code): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src/nested');
    $this->writeFile($root . '/src/Example.php', '<?php echo 1;');
    $this->writeFile($root . '/other/Example.phplus', '<?php echo 1;');
    $this->writeFile($container . '/Outside.phplus', '<?php echo 1;');
    $input = [
        'command' => 'check',
        '--working-directory' => $root,
    ];

    if ($file !== null) {
        $input['path'] = $file;
    }

    $tester = runStageTwoCommand($input);

    expect($tester->getStatusCode())->toBe($status);

    if ($code !== null) {
        expect($tester->getDisplay())->toContain('Error[' . $code . ']');
    }
})->with([
    'missing argument' => [null, ExitCode::Success->value, null],
    'directory' => ['src/nested', ExitCode::Success->value, null],
    'ordinary PHP file' => ['src/Example.php', ExitCode::Success->value, null],
    'outside project' => ['../Outside.phplus', ExitCode::InvalidProject->value, 'P0016'],
    'outside configured roots' => ['other/Example.phplus', ExitCode::InvalidProject->value, 'P1005'],
]);

test('an explicit source symlink cannot resolve outside the project', function (): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $this->writeConfiguration($root);
    $this->writeFile($container . '/Outside.phplus', '<?php echo 1;');
    $this->createDirectory($root . '/src');
    symlink($container . '/Outside.phplus', $root . '/src/Linked.phplus');
    $tester = runStageTwoCommand([
        'command' => 'check',
        'path' => 'src/Linked.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($tester->getDisplay())->toContain('Error[P0016]: File Is Outside Project Root');
});

test('check JSON output uses the diagnostic envelope for success and failure', function (bool $valid): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = $valid ? '<?php echo 1;' : '<?php echo ;';
    $this->writeFile($root . '/src/Example.phplus', $contents);
    $tester = runStageTwoCommand([
        'command' => 'check',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(
        $valid ? ExitCode::Success->value : ExitCode::DiagnosticsReported->value,
    )->and($json['version'])->toBe(1)
        ->and($json['summary']['errors'])->toBe($valid ? 0 : 1)
        ->and($json['diagnostics'])->toHaveCount($valid ? 0 : 1);

    if (!$valid) {
        expect($json['diagnostics'][0]['code'])->toBe('P1001')
            ->and($json['diagnostics'][0]['location']['file'])->toBe('src/Example.phplus');
    }
})->with(['valid' => true, 'invalid' => false]);

test('build preserves a nested source byte for byte and builds no sibling', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = "<?php\r\n\r\n// formatting retained\r\n\$value = <<<'TEXT'\r\nhello\r\nTEXT;\r\necho \$value;\r\n";
    $this->writeFile($root . '/src/Domain/Example.phplus', $contents);
    $this->writeFile($root . '/src/Domain/Sibling.phplus', '<?php echo "sibling";');
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Domain/Example.phplus',
        '--working-directory' => $root,
    ]);
    $outputPath = $root . '/build/phplus/Domain/Example.php';

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Built src/Domain/Example.phplus -> build/phplus/Domain/Example.php')
        ->and(file_get_contents($outputPath))->toBe($contents)
        ->and(file_exists($root . '/build/phplus/Domain/Sibling.php'))->toBeFalse();
});

test('build preserves inline HTML and closing tags', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = "Before\n<?php echo 'inside'; ?>\nAfter\n";
    $this->writeFile($root . '/src/Page.phplus', $contents);
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Page.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root . '/build/phplus/Page.php'))->toBe($contents);
});

test('build chooses the most specific configured source root deterministically', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['src', 'src/Domain']]);
    $this->writeFile($root . '/src/Domain/Example.phplus', '<?php echo 1;');
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Domain/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/phplus/Example.php'))->toBeTrue()
        ->and(file_exists($root . '/build/phplus/Domain/Example.php'))->toBeFalse();
});

test('an invalid rebuild preserves the previous generated PHP', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $outputPath = $root . '/build/phplus/Example.php';
    $this->writeFile($outputPath, "<?php echo 'previous';\n");
    $this->writeFile($root . '/src/Example.phplus', '<?php echo ;');
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P1001]')
        ->and(file_get_contents($outputPath))->toBe("<?php echo 'previous';\n");
});

test('build write failures become structured output diagnostics', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, ['output' => 'blocked']);
    $this->writeFile($root . '/src/Example.phplus', '<?php echo 1;');
    $this->writeFile($root . '/blocked', 'not a directory');
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($tester->getDisplay())->toContain('Error[P7001]: Generated PHP Could Not Be Written')
        ->and($tester->getDisplay())->not->toContain('mkdir(');
});

test('build refuses an output root symbolic link that could escape the project', function (): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $outside = $container . '/outside';
    $this->writeConfiguration($root, ['output' => 'linked-output']);
    $this->writeFile($root . '/src/Example.phplus', '<?php echo 1;');
    $this->createDirectory($outside);
    symlink($outside, $root . '/linked-output');
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($tester->getDisplay())->toContain('Error[P7001]')
        ->and(file_exists($outside . '/Example.php'))->toBeFalse();
});

test('dump ast emits deterministic node and source attribute data', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Example.phplus', "<?php\n// note\nfunction example(): int { return 1; }\n");
    $first = runStageTwoCommand([
        'command' => 'dump:ast',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);
    $second = runStageTwoCommand([
        'command' => 'dump:ast',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
    ]);

    expect($first->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($first->getDisplay())->toBe($second->getDisplay())
        ->and($first->getDisplay())->toContain('Stmt_Function')
        ->and($first->getDisplay())->toContain('comments:')
        ->and($first->getDisplay())->toContain('startFilePos=')
        ->and($first->getDisplay())->toContain('startTokenPos=');
});

test('dump ast JSON uses a stable wrapper and a project-relative file path', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Example.phplus', '<?php echo 1;');
    $tester = runStageTwoCommand([
        'command' => 'dump:ast',
        'path' => 'src/Example.phplus',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $json = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($json['version'])->toBe(1)
        ->and($json['file'])->toBe('src/Example.phplus')
        ->and($json['ast'])->toContain('Stmt_Echo')
        ->and($tester->getDisplay())->not->toContain($root);
});

test('dump ast emits diagnostics instead of a partial success dump', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.phplus', '<?php function broken(');
    $tester = runStageTwoCommand([
        'command' => 'dump:ast',
        'path' => 'src/Invalid.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P1001]')
        ->and($tester->getDisplay())->not->toContain('Position Attributes:');
});

test('every valid parsing fixture builds to PHP that passes lint', function (string $fixture): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Valid/' . $fixture);
    $sourcePath = $root . '/src/' . $fixture;
    $outputPath = $root . '/build/phplus/' . substr($fixture, 0, -strlen('.phplus')) . '.php';
    $this->writeFile($sourcePath, $contents);
    $tester = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/' . $fixture,
        '--working-directory' => $root,
    ]);
    $lint = new Process([PHP_BINARY, '-l', $outputPath]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($lint->run())->toBe(0)
        ->and($lint->getOutput())->toContain('No syntax errors detected');
})->with(['Empty.phplus', 'Basic.phplus', 'ModernPhp84.phplus', 'Runtime.phplus']);

test('a built executable fixture retains its runtime behavior', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Valid/Runtime.phplus');
    $this->writeFile($root . '/src/Runtime.phplus', $contents);
    $build = runStageTwoCommand([
        'command' => 'build',
        'path' => 'src/Runtime.phplus',
        '--working-directory' => $root,
    ]);
    $process = new Process([PHP_BINARY, $root . '/build/phplus/Runtime.php']);

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($process->run())->toBe(0)
        ->and($process->getOutput())->toBe("PHPLUS ORDINARY PHP FRONTEND\n")
        ->and($process->getErrorOutput())->toBe('');
});

test('PHPlus extension syntax remains invalid ordinary PHP in this frontend', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Parsing/Invalid/ExtensionSyntax.phplus');
    $this->writeFile($root . '/src/ExtensionSyntax.phplus', $contents);
    $tester = runStageTwoCommand([
        'command' => 'check',
        'path' => 'src/ExtensionSyntax.phplus',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P1001]');
});
