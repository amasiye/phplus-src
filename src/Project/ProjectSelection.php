<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

final readonly class ProjectSelection
{
    public function __construct(
        public SourceSet $analysisSources,
        public SourceSet $outputSources,
    ) {}
}
