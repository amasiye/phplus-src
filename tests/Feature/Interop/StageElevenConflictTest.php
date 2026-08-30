<?php

declare(strict_types=1);

use Amasiye\Ppphp\Cli\Enumerations\ExitCode;
use Tests\Support\StageElevenProject;

test('duplicate project classes and functions receive compiler-owned diagnostics', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['source' => ['src', 'legacy']]);
    $this->writeFile($root . '/src/Duplicate.ppphp', <<<'PPP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PPP);
    $this->writeFile($root . '/legacy/Duplicate.php', <<<'PHP'
<?php
namespace Example;
final class Duplicate {}
function duplicate(): void {}
PHP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(substr_count($check->getDisplay(), 'Error[P2034]: Duplicate Project Declaration'))->toBe(2)
        ->and($check->getDisplay())->toContain('Example\\Duplicate', 'Example\\duplicate')
        ->toContain('src/Duplicate.ppphp', 'legacy/Duplicate.php', 'Related:')
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp/Duplicate.php'))->toBeFalse();
});

test('configured stubs may intentionally describe project declarations', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Boundary.php', <<<'PHP'
<?php
final class Boundary { public function value(): string { return 'ok'; } }
PHP);
    $this->writeFile($root . '/stubs/Boundary.stub.php', <<<'PHP'
<?php
final class Boundary { public function value(): string {} }
PHP);
    $this->writeFile($root . '/src/Caller.ppphp', <<<'PPP'
<?php
function callBoundary(Boundary $boundary): string { return $boundary->value(); }
PPP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::Success->value, $check->getDisplay())
        ->and($check->getDisplay())->not->toContain('P2034');
});

test('existing cross-boundary conflict diagnostics remain specific and atomic', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Conflict.ppphp', <<<'PPP'
<?php
class Box<T> {}
/** @template U */
class DocumentedBox<T> {}
class Failure extends RuntimeException {}
/** @throws LogicException */
function conflict(): void throws Failure {}
PPP);

    $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);
    $build = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and($check->getDisplay())->toContain(
            'Error[P3010]: Generic Documentation Conflicts With Native Syntax',
            'Error[P4007]: Throws Documentation Conflicts With Native Clause',
        )
        ->and($build->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value)
        ->and(file_exists($root . '/build/ppphp'))->toBeFalse();
});

test('mixed generic array and checked-error contract violations fail at original sources', function (): void {
    $cases = [
        [
            'src/Infrastructure/PersonRepository.ppphp',
            'implements Repository<Person>',
            'implements Repository<string>',
        ],
        [
            'legacy/Presentation/LegacyPresenter.php',
            '@param Box<Person> $box',
            '@param Box<string> $box',
        ],
        [
            'legacy/Http/LegacyController.php',
            "return \$tags[0] . ':' . \$scores['quality'];",
            "return \$tags[0] . ':' . strtoupper(\$scores['quality']);",
        ],
        [
            'src/Service/PersonService.ppphp',
            'public function tags(): array<string>',
            'public function tags(): array<int>',
        ],
        [
            'src/Service/PersonService.ppphp',
            'public function load(string $id): Person throws LegacyUnavailable',
            'public function load(string $id): Person',
        ],
    ];

    $temporary = $this->createTemporaryDirectory();

    foreach ($cases as $index => [$file, $search, $replacement]) {
        $root = $temporary . '/case-' . $index;
        StageElevenProject::copyTree(dirname(__DIR__, 3) . '/examples/mixed-application', $root);
        $path = $root . '/' . $file;
        $source = (string) file_get_contents($path);
        $changed = str_replace($search, $replacement, $source);
        expect($changed)->not->toBe($source);
        $this->writeFile($path, $changed);

        $check = StageElevenProject::runCommand(['command' => 'check', '--working-directory' => $root]);

        expect($check->getStatusCode())->toBe(ExitCode::DiagnosticsReported->value, $file . "\n" . $check->getDisplay())
            ->and($check->getDisplay())->toContain($file)
            ->not->toContain('.ppphp-cache/analysis', 'build/ppphp/');
    }
});

test('compiled and copied output collisions preserve the previous complete output', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->writeFile($root . '/src/Foo.php', "<?php\nfinal class LegacyFoo {}\n");
    $successful = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);
    $before = StageElevenProject::captureTree($root . '/build/ppphp');
    $this->writeFile($root . '/src/Foo.ppphp', "<?php\nfinal class GeneratedFoo {}\n");

    $collision = StageElevenProject::runCommand(['command' => 'build', '--working-directory' => $root]);

    expect($successful->getStatusCode())->toBe(ExitCode::Success->value)
        ->and($collision->getStatusCode())->toBe(ExitCode::OutputValidationFailed->value)
        ->and($collision->getDisplay())->toContain('Error[P7002]: Generated PHP Output Path Collision')
        ->and(StageElevenProject::captureTree($root . '/build/ppphp'))->toBe($before);
});

test('Composer projection conflicts leave composer json byte-identical', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    $this->createDirectory($root . '/src');
    $original = json_encode([
        'autoload' => ['psr-4' => ['Example\\' => 'somewhere-else/']],
        'extra' => ['ppphp' => [
            'source-autoload' => [
                'psr-4' => ['Example\\' => 'src/'],
                'classmap' => [],
                'files' => [],
            ],
            'source-autoload-dev' => [
                'psr-4' => new stdClass(),
                'classmap' => [],
                'files' => [],
            ],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $this->writeFile($root . '/composer.json', $original);

    $configure = StageElevenProject::runCommand([
        'command' => 'composer:configure',
        '--working-directory' => $root,
    ]);

    expect($configure->getStatusCode())->toBe(ExitCode::InvalidProject->value)
        ->and($configure->getDisplay())->toContain('Error[P6011]: Composer Runtime Mapping Conflicts With Build Output')
        ->and(file_get_contents($root . '/composer.json'))->toBe($original);
});
