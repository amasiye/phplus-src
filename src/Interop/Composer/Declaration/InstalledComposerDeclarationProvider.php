<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer\Declaration;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\Composer\ComposerDependencyDeclarationLoader;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;

final readonly class InstalledComposerDeclarationProvider implements DependencyDeclarationProvider
{
    public function __construct(private ComposerDependencyDeclarationLoader $loader = new ComposerDependencyDeclarationLoader()) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function provide(Project $project, iterable $projectFiles): ProjectParseResult
    {
        return $this->loader->load($project->composer, $projectFiles);
    }
}
