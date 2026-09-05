<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Output\OutputPathResolver;
use Atatusoft\Ppphp\Compiler\Output\OutputPlanner;
use Atatusoft\Ppphp\Config\ProjectConfigLoader;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Project\ProjectLoader;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;

test('current limitation: independent framework roots flatten and collide before emission', function (): void {
    $root = (string) realpath($this->createTemporaryDirectory());
    $this->writeFile($root . '/bootstrap/app.php', '<?php return [];');
    $this->writeFile($root . '/config/app.php', '<?php return [];');
    $this->writeConfiguration($root, ['source' => ['bootstrap', 'config']]);
    $configuration = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();

    $resolver = new OutputPathResolver();
    foreach (['bootstrap', 'config'] as $directory) {
        $source = new ProjectSource(
            $root . '/' . $directory . '/app.php',
            $root . '/' . $directory,
            FileKind::Php,
            $root,
        );
        expect($source->relativePath)->toBe('app.php')
            ->and($resolver->resolveRelative($source))->toBe('app.php')
            ->and($resolver->resolve($configuration, $source))->toBe($root . '/build/ppphp/app.php');
    }

    $result = (new OutputPlanner())->plan($project, $project->sources);
    $diagnostics = iterator_to_array($result->diagnostics);
    expect($result->plan)->toBeNull()
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->code)->toBe(DiagnosticCode::OutputPathCollision)
        ->and($diagnostics[0]->message)->toContain('bootstrap/app.php', 'config/app.php', 'build/ppphp/app.php')
        ->and(is_dir($root . '/build'))->toBeFalse();
});

test('current limitation: an extensionless framework command cannot be a configured file root', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeFile($root . '/artisan', '#!/usr/bin/env php' . "\n<?php echo 'probe';");
    $this->writeConfiguration($root, ['source' => ['artisan']]);
    $result = (new ProjectConfigLoader())->load($root, requireSourceDirectories: true);
    $codes = array_map(static fn ($diagnostic) => $diagnostic->code, iterator_to_array($result->diagnostics));
    expect($result->configuration)->toBeNull()
        ->and($codes)->toContain(DiagnosticCode::SourcePathNotDirectory);
});

test('current release rejects a newer target despite a newer host interpreter', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root, ['targetPhpVersion' => '8.5']);
    $result = (new ProjectConfigLoader())->load($root);
    expect($result->configuration)->toBeNull()
        ->and(array_map(static fn ($diagnostic) => $diagnostic->code, iterator_to_array($result->diagnostics)))
        ->toContain(DiagnosticCode::UnsupportedTargetPhpVersion);
});
