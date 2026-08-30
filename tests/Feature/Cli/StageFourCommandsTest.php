<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

function runStageFourCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('recognized extension syntax blocks checking and building without raw PHP errors or output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Feature.ppphp', '<?php final class Box<T> {}');
    $check = runStageFourCommand([
        'command' => 'check',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);
    $build = runStageFourCommand([
        'command' => 'build',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('Error[P3001]: Generic Syntax Is Not Active')
        ->and($check->getDisplay())->not->toContain('Error[P1001]')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Feature.php'))->toBeFalse();
});

test('dump ast exposes extension nodes normalized PHP and source mapping despite inactive diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile(
        $root . '/src/Feature.ppphp',
        '<?php function f(): int { return when (true) { return 1; } else { return 0; }; }',
    );
    $dump = runStageFourCommand([
        'command' => 'dump:ast',
        'path' => 'src/Feature.ppphp',
        '--working-directory' => $root,
    ]);

    expect($dump->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($dump->getDisplay())->toContain('Error[P5001]: When Syntax Is Not Active')
        ->and($dump->getDisplay())->toContain('WhenExpression')
        ->and($dump->getDisplay())->toContain('Normalized PHP AST:')
        ->and($dump->getDisplay())->toContain('Normalization:')
        ->and($dump->getDisplay())->not->toContain('Error[P1001]');
});
