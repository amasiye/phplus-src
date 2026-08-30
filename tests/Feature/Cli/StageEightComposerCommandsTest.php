<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageEightComposerCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('composer configure supports dry runs explicit writes follow-up guidance and JSON output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $original = json_encode([
        'name' => 'example/application',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $this->writeFile($root . '/composer.json', $original);

    $dryRun = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
        '--dry-run' => true,
    ]);

    expect($dryRun->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($dryRun->getDisplay())->toContain('Would update composer.json')
        ->toContain('composer update --lock')
        ->toContain('composer dump-autoload')
        ->and(file_get_contents($root . '/composer.json'))->toBe($original);

    $write = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);
    $configured = (string) file_get_contents($root . '/composer.json');

    expect($write->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($write->getDisplay())->toContain('Updated composer.json')
        ->and($configured)->not->toBe($original);

    $json = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
        '--format' => 'json',
    ]);
    $payload = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($json->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($payload['version'])->toBe(1)
        ->and($payload['diagnostics'])->toBe([])
        ->and($payload['summary'])->toBe(['errors' => 0, 'warnings' => 0, 'notes' => 0]);
});

test('build warns until root Composer application mappings target generated PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Thing.ppphp', '<?php namespace App; final class Thing {}');
    $this->writeFile($root . '/composer.json', json_encode([
        'name' => 'example/application',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));

    $before = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($before->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($before->getDisplay())->toContain('Warning[P6008]: Composer Autoload Does Not Target Build Output')
        ->toContain('autoload.psr-4.App\\')
        ->toContain('src/')
        ->toContain('build/ppphp/');

    $configure = runStageEightComposerCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);
    $after = runStageEightComposerCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($configure->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($after->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($after->getDisplay())->not->toContain('P6008')
        ->and(file_exists($root . '/build/ppphp/Thing.php'))->toBeTrue();
});
