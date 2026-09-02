<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer\Declaration;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;

interface DependencyDeclarationProvider
{
    /** @param iterable<ParsedFile> $projectFiles */
    public function provide(Project $project, iterable $projectFiles): ProjectParseResult;
}
