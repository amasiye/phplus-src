<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Interop\Composer\ComposerProject;
use Amasiye\Phplus\Interop\Stub\StubRepository;
use Amasiye\Phplus\Source\SourceManager;

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
