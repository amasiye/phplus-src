<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Interop\Composer\ComposerProject;
use Amasiye\Ppphp\Interop\Stub\StubRepository;
use Amasiye\Ppphp\Source\SourceManager;

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
