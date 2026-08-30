<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Application;
use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

function runStageSixClosureCommand(array $input): ApplicationTester
{
    $application = new Application();
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run(['--no-ansi' => true, ...$input]);

    return $tester;
}

test('a pathless mixed build preserves relative includes and executes file-scope typed declarations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $bootstrap = "<?php\nrequire_once __DIR__ . '/Core/Person.php';\n";
    $this->writeFile($root . '/src/Core/Person.ppphp', <<<'PPP'
<?php
namespace Demo;
final class Person
{
    public function __construct(public string $name) {}
}
PPP);
    $this->writeFile($root . '/src/bootstrap.php', $bootstrap);
    $this->writeFile($root . '/src/index.ppphp', <<<'PPP'
<?php
require_once __DIR__ . '/bootstrap.php';
Demo\Person $person = new Demo\Person('Andrew');
echo $person->name;
PPP);

    $build = runStageSixClosureCommand([
        'command' => 'build',
        '--working-directory' => $root,
    ]);
    $runtime = new Process([PHP_BINARY, $root . '/build/ppphp/index.php']);
    $runtime->run();

    expect($build->getStatusCode())->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root . '/build/ppphp/bootstrap.php'))->toBe($bootstrap)
        ->and(file_exists($root . '/build/ppphp/Core/Person.php'))->toBeTrue()
        ->and(file_exists($root . '/build/ppphp/index.php'))->toBeTrue()
        ->and($runtime->getExitCode())->toBe(0)
        ->and($runtime->getOutput())->toBe('Andrew')
        ->and($runtime->getErrorOutput())->toBe('');
});
