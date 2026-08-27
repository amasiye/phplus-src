<?php

declare(strict_types=1);

use Amasiye\Phplus\Cli\Application;
use Amasiye\Phplus\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageOneCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('init creates a valid configuration and compiler-owned directories without source files', function (): void {
    $root = $this->temporaryDirectory();
    $tester = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);
    $configuration = json_decode(
        (string) file_get_contents($root . '/phplus.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('Created phplus.json.')
        ->and($configuration['targetPhpVersion'])->toBe('8.4')
        ->and(is_dir($root . '/build/phplus'))->toBeTrue()
        ->and(is_dir($root . '/.phplus-cache'))->toBeTrue()
        ->and(is_dir($root . '/stubs'))->toBeTrue()
        ->and(file_exists($root . '/src'))->toBeFalse();
});

test('init refuses overwrite unless force is supplied and never prompts', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeFile($root . '/phplus.json', "sentinel\n");
    $refused = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);

    expect($refused->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($refused->getDisplay())->toContain('Error[P0009]: Project Configuration Already Exists')
        ->and(file_get_contents($root . '/phplus.json'))->toBe("sentinel\n");

    $forced = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($forced->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(json_decode((string) file_get_contents($root . '/phplus.json'), true))->toBeArray();
});

test('init refuses configuration and owned-directory symlinks', function (string $linkPath): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $target = $container . '/outside';
    $this->createDirectory($root);
    $this->createDirectory($target);

    if ($linkPath === 'phplus.json') {
        $this->writeFile($target . '/configuration.json', "preserved\n");
        symlink($target . '/configuration.json', $root . '/phplus.json');
    } else {
        symlink($target, $root . '/.phplus-cache');
    }

    $tester = runStageOneCommand([
        'command' => 'init',
        '--working-directory' => $root,
        '--force' => true,
        '--no-interaction' => true,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($tester->getDisplay())->toContain('Error[P0008]: Unsafe Project Path');

    if ($linkPath === 'phplus.json') {
        expect(file_get_contents($target . '/configuration.json'))->toBe("preserved\n");
    } else {
        expect(is_dir($target))->toBeTrue();
    }
})->with(['phplus.json', '.phplus-cache']);

test('clean removes only configured output and cache directories', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/main.php', '<?php');
    $this->writeFile($root . '/build/phplus/nested/output.php', '<?php');
    $this->writeFile($root . '/.phplus-cache/cache.bin', 'cache');
    $this->writeFile($root . '/stubs/library.stub.php', '<?php');
    $this->writeFile($root . '/keep.txt', 'keep');

    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
        '--no-interaction' => true,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/phplus'))->toBeFalse()
        ->and(file_exists($root . '/.phplus-cache'))->toBeFalse()
        ->and(file_exists($root . '/src/main.php'))->toBeTrue()
        ->and(file_exists($root . '/stubs/library.stub.php'))->toBeTrue()
        ->and(file_exists($root . '/phplus.json'))->toBeTrue()
        ->and(file_exists($root . '/keep.txt'))->toBeTrue();
});

test('clean accepts missing owned directories and dry-run preserves existing paths', function (): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root);
    $missing = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($missing->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($missing->getDisplay())->toContain('Nothing to clean.');

    $this->writeFile($root . '/build/phplus/output.php', '<?php');
    $this->writeFile($root . '/.phplus-cache/cache.bin', 'cache');
    $dryRun = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
        '--dry-run' => true,
    ]);

    expect($dryRun->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dryRun->getDisplay())->toContain('Would remove build/phplus.')
        ->and(file_exists($root . '/build/phplus/output.php'))->toBeTrue()
        ->and(file_exists($root . '/.phplus-cache/cache.bin'))->toBeTrue();
});

test('clean refuses project-root source-overlapping and outside owned paths', function (array $overrides, string $code): void {
    $root = $this->temporaryDirectory();
    $this->writeConfiguration($root, $overrides);
    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($tester->getDisplay())->toContain('Error[' . $code . ']');
})->with([
    'project root' => [['output' => '.'], 'P0008'],
    'source overlap' => [['cache' => 'src/cache'], 'P0013'],
    'outside root' => [['output' => '../output'], 'P0008'],
]);

test('clean unlinks an owned directory symlink without following its target', function (): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $target = $container . '/outside';
    $this->createDirectory($root);
    $this->writeConfiguration($root, ['output' => 'owned-link']);
    $this->writeFile($target . '/preserved.txt', 'preserved');
    symlink($target, $root . '/owned-link');

    $tester = runStageOneCommand([
        'command' => 'clean',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(is_link($root . '/owned-link'))->toBeFalse()
        ->and(file_exists($target . '/preserved.txt'))->toBeTrue();
});

test('check and build validate the project then report the unavailable frontend', function (string $command): void {
    $root = $this->temporaryDirectory();
    $this->createDirectory($root . '/src');
    $this->writeConfiguration($root);
    $tester = runStageOneCommand([
        'command' => $command,
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P0010]: Compiler Frontend Is Not Available')
        ->and(file_exists($root . '/build/phplus'))->toBeFalse();
})->with(['check', 'build']);

test('JSON command output is valid and contains no ANSI', function (): void {
    $root = $this->temporaryDirectory();
    $this->createDirectory($root . '/src');
    $this->writeConfiguration($root);
    $tester = runStageOneCommand([
        'command' => 'check',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($decoded['diagnostics'][0]['code'])->toBe('P0010')
        ->and($decoded['summary']['errors'])->toBe(1)
        ->and($tester->getDisplay())->not->toContain("\e[");
});

test('dump ast validates the requested path before reporting frontend capability', function (string $file, string $code, int $exitCode): void {
    $container = $this->temporaryDirectory();
    $root = $container . '/project';
    $this->createDirectory($root . '/src');
    $this->writeFile($root . '/src/main.php', '<?php');
    $this->writeFile($container . '/outside.php', '<?php');
    $this->writeConfiguration($root);
    $tester = runStageOneCommand([
        'command' => 'dump:ast',
        'file' => $file,
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe($exitCode)
        ->and($tester->getDisplay())->toContain('Error[' . $code . ']');
})->with([
    'missing file' => ['src/missing.php', 'P0018', ExitCode::InvalidProject->value],
    'directory' => ['src', 'P0019', ExitCode::InvalidProject->value],
    'outside project' => ['../outside.php', 'P0016', ExitCode::InvalidProject->value],
    'valid source' => ['src/main.php', 'P0010', ExitCode::DiagnosticsReported->value],
]);

test('debug controls internal exception details at the CLI boundary', function (): void {
    $application = new Application();
    $application->setAutoExit(false);
    $application->addCommand(new class extends Command {
        public function __construct()
        {
            parent::__construct('explode');
        }

        protected function configure(): void
        {
            $this
                ->addOption('debug', null, InputOption::VALUE_NONE)
                ->addOption('format', null, InputOption::VALUE_REQUIRED, default: 'console');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            throw new RuntimeException('private failure detail');
        }
    });
    $tester = new ApplicationTester($application);
    $tester->run(['command' => 'explode', '--no-ansi' => true]);

    expect($tester->getStatusCode())->toBe(ExitCode::InternalCompilerFailure->value)
        ->and($tester->getDisplay())->toContain('Error[P9001]: Internal Compiler Error')
        ->and($tester->getDisplay())->not->toContain('private failure detail');

    $debugTester = new ApplicationTester($application);
    $debugTester->run(['command' => 'explode', '--debug' => true, '--no-ansi' => true]);

    expect($debugTester->getStatusCode())->toBe(ExitCode::InternalCompilerFailure->value)
        ->and($debugTester->getDisplay())->toContain('private failure detail')
        ->and($debugTester->getDisplay())->toContain(RuntimeException::class);
});
