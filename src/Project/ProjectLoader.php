<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Interop\Composer\ComposerResolver;
use Atatusoft\Ppphp\Interop\Stub\StubLoader;
use Atatusoft\Ppphp\Source\SourceManager;

final readonly class ProjectLoader
{
    public function __construct(
        private FileDiscovery $fileDiscovery = new FileDiscovery(),
        private ComposerResolver $composerResolver = new ComposerResolver(),
        private StubLoader $stubLoader = new StubLoader(),
    ) {}

    public function load(ProjectConfig $configuration): ProjectLoadResult
    {
        $diagnostics = new DiagnosticBag();
        $discovery = $this->fileDiscovery->discover($configuration);
        $composer = $this->composerResolver->resolve($configuration->projectRoot, [
            $configuration->outputPath,
            $configuration->cachePath,
        ]);
        $stubs = $this->stubLoader->load($configuration);
        $diagnostics->addAll($discovery->diagnostics);
        $diagnostics->addAll($composer->diagnostics);
        $diagnostics->addAll($stubs->diagnostics);

        if (
            $diagnostics->hasErrors
            || $discovery->sources === null
            || $composer->project === null
            || $stubs->repository === null
        ) {
            return new ProjectLoadResult(null, $diagnostics);
        }

        $graph = new DependencyGraph();

        foreach ($discovery->sources as $source) {
            $graph->addNode($source->path);
        }

        foreach ($stubs->repository as $stub) {
            $graph->addNode($stub->path);
        }

        if ($composer->project->configurationPath !== null) {
            $graph->addNode($composer->project->configurationPath);
        }

        foreach ([...$composer->project->projectAutoload->paths, ...$composer->project->dependencyAutoload->paths] as $path) {
            $graph->addNode($path);
        }

        return new ProjectLoadResult(new Project(
            $configuration,
            $discovery->sources,
            $composer->project,
            $stubs->repository,
            $graph,
            new SourceManager($configuration->projectRoot),
        ), $diagnostics);
    }
}
