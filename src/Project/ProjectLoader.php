<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Interop\Composer\ComposerResolver;
use Amasiye\Phplus\Interop\Stub\StubLoader;
use Amasiye\Phplus\Source\SourceManager;

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
        $composer = $this->composerResolver->resolve($configuration->projectRoot);
        $stubs = $this->stubLoader->load($configuration);
        $diagnostics->addAll($discovery->diagnostics);
        $diagnostics->addAll($composer->diagnostics);
        $diagnostics->addAll($stubs->diagnostics);

        if (
            $diagnostics->hasErrors()
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

        foreach ([...$composer->project->projectAutoload->paths(), ...$composer->project->dependencyAutoload->paths()] as $path) {
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
