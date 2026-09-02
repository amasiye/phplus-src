<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer\Declaration;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;

final readonly class InstalledComposerDeclarationProvider implements DependencyDeclarationProvider
{
    public function __construct(private ComposerDependencyDeclarationLoader $loader = new ComposerDependencyDeclarationLoader()) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function provide(Project $project, iterable $projectFiles): ProjectParseResult
    {
        return $this->loader->load($project->composer, $projectFiles);
    }
}
