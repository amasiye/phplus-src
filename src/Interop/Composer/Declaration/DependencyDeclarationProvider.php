<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer\Declaration;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;

interface DependencyDeclarationProvider
{
    /** @param iterable<ParsedFile> $projectFiles */
    public function provide(Project $project, iterable $projectFiles): ProjectParseResult;
}
