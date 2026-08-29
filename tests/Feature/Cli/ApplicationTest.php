<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

test('the application uses the ppphp executable name', function (): void {
    $application = new Application();

    expect($application->getName())->toBe('ppphp')
        ->and($application->getVersion())->toBe('development');
});

test('the version option reports the development version', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, 'bin/ppphp', '--version', '--no-ansi'], $projectRoot);

    expect($process->run())->toBe(Command::SUCCESS)
        ->and(trim($process->getOutput()))->toBe('ppphp development');
});

test('the help option succeeds', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, 'bin/ppphp', '--help', '--no-ansi'], $projectRoot);

    expect($process->run())->toBe(Command::SUCCESS)
        ->and($process->getOutput())->toContain('Usage:')
        ->and($process->getOutput())->toContain('Options:');
});

test('the Composer bin proxy supplies the installation autoloader', function (): void {
    $projectRoot = dirname(__DIR__, 3);
    $temporaryDirectory = sys_get_temp_dir() . '/ppphp-bin-' . bin2hex(random_bytes(8));
    $packageBinDirectory = $temporaryDirectory . '/vendor/atatusoft-ltd/ppphp-src/bin';
    $proxyDirectory = $temporaryDirectory . '/vendor/bin';
    $autoloadPath = $temporaryDirectory . '/vendor/autoload.php';
    $packageBinPath = $packageBinDirectory . '/ppphp';
    $proxyPath = $proxyDirectory . '/ppphp';

    mkdir($packageBinDirectory, 0777, true);
    mkdir($proxyDirectory, 0777, true);

    copy($projectRoot . '/bin/ppphp', $packageBinPath);
    file_put_contents(
        $autoloadPath,
        "<?php\nrequire " . var_export($projectRoot . '/vendor/autoload.php', true) . ";\n",
    );
    file_put_contents(
        $proxyPath,
        <<<'PHP'
<?php

$GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';

require __DIR__ . '/../atatusoft-ltd/ppphp-src/bin/ppphp';
PHP,
    );

    try {
        $process = new Process([PHP_BINARY, $proxyPath, '--version', '--no-ansi']);

        expect($process->run())->toBe(Command::SUCCESS)
            ->and(trim($process->getOutput()))->toBe('ppphp development');
    } finally {
        unlink($proxyPath);
        unlink($packageBinPath);
        unlink($autoloadPath);
        rmdir($proxyDirectory);
        rmdir($packageBinDirectory);
        rmdir(dirname($packageBinDirectory));
        rmdir(dirname($packageBinDirectory, 2));
        rmdir($temporaryDirectory . '/vendor');
        rmdir($temporaryDirectory);
    }
});
