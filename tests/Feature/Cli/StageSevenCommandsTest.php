<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageSevenCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('checked-error projects check build lint and run as ordinary PHP', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $bootstrap = <<<'PHP'
<?php
final class UserNotFound extends RuntimeException {}
final class StorageFailure extends RuntimeException {}
final readonly class User
{
    public function __construct(public string $id) {}
}
PHP;
    $this->writeFile($root . '/src/bootstrap.php', $bootstrap);
    $this->writeFile($root . '/src/index.ppp', <<<'PPP'
<?php
require_once __DIR__ . '/bootstrap.php';

function loadUser(string $id): User throws UserNotFound, StorageFailure
{
    if ($id === '') {
        throw new UserNotFound('missing');
    }

    return new User($id);
}

try {
    User $user = loadUser('1');
    echo $user->id;
} catch (UserNotFound|StorageFailure $error) {
    echo $error->getMessage();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $generated = file_get_contents($root . '/build/ppphp/index.php');
    $lint = new Process([PHP_BINARY, '-l', $root . '/build/ppphp/index.php']);
    $lint->run();
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/index.php']);
    $runtime->run();

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($generated)->toBeString()
        ->toContain('@throws \\UserNotFound|\\StorageFailure')
        ->not->toContain('throws UserNotFound')
        ->and(file_get_contents($root . '/build/ppphp/bootstrap.php'))->toBe($bootstrap)
        ->and($lint->isSuccessful())->toBeTrue()
        ->and($runtime->isSuccessful())->toBeTrue()
        ->and($runtime->getOutput())->toBe('1');
});

test('unhandled called errors block checks and builds without exposing analysis paths', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Invalid.ppp', <<<'PPP'
<?php
final class Failure extends RuntimeException {}
function load(): void throws Failure
{
    throw new Failure();
}
function main(): void
{
    load();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain('Error[P4003]: Checked Error Is Not Handled')
        ->toContain('src/Invalid.ppp:')
        ->not->toContain('.ppphp-cache/analysis')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Invalid.php'))->toBeFalse();
});

test('dynamic call warnings do not fail checks or block builds', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Dynamic.ppp', <<<'PPP'
<?php
function invoke(callable $callback): void
{
    $callback();
}
PPP);
    $check = runStageSevenCommand([
        'command' => 'check',
        '--working-directory' => $root,
    ]);
    $build = runStageSevenCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($check->getDisplay())->toContain('Warning[P4005]: Unchecked Call Boundary')
        ->and($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_exists($root . '/build/ppphp/Dynamic.php'))->toBeTrue();
});

test('focused checks use valid unselected checked-error contracts as context', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Api.ppp', <<<'PPP'
<?php
final class ApiFailure extends RuntimeException {}
function callApi(): void throws ApiFailure
{
    throw new ApiFailure();
}
PPP);
    $this->writeFile($root . '/src/Caller.ppp', <<<'PPP'
<?php
function caller(): void
{
    callApi();
}
PPP);
    $this->writeFile($root . '/src/Unrelated.ppp', '<?php function broken(: void {}');
    $tester = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P4003]: Checked Error Is Not Handled')
        ->toContain('src/Caller.ppp:')
        ->not->toContain('Unrelated.ppp')
        ->not->toContain('.ppphp-cache/analysis');
});

test('project PHPDoc checked-error contracts participate in focused commands', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Boundary.php', <<<'PHP'
<?php
final class BoundaryFailure extends RuntimeException {}
/** @throws BoundaryFailure */
function boundary(): void {}
PHP);
    $this->writeFile($root . '/src/Caller.ppp', <<<'PPP'
<?php
function caller(): void
{
    boundary();
}
PPP);
    $tester = runStageSevenCommand([
        'command' => 'check',
        'path' => 'src/Caller.ppp',
        '--working-directory' => $root,
    ]);

    expect($tester->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($tester->getDisplay())->toContain('Error[P4003]: Checked Error Is Not Handled')
        ->toContain('BoundaryFailure');
});
