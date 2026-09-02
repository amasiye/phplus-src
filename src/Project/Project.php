<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Interop\Composer\ComposerProject;
use Atatusoft\Ppphp\Interop\Stub\StubRepository;
use Atatusoft\Ppphp\Source\SourceManager;

final readonly class Project
{
    public function __construct(
        public ProjectConfig $configuration,
        public SourceSet $sources,
        public ComposerProject $composer,
        public StubRepository $stubs,
        public DependencyGraph $dependencyGraph,
        public SourceManager $sourceManager,
    ) {}
}
