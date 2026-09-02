<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer\Declaration;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\Composer\Index\DependencyDeclarationIndexReader;
use Atatusoft\Ppphp\Project\Project;
use Atatusoft\Ppphp\Project\ProjectParseResult;

final readonly class PortableDependencyIndexProvider implements DependencyDeclarationProvider
{
    public function __construct(
        private string $manifestPath,
        private ?string $expectedManifestHash = null,
        private DependencyDeclarationIndexReader $reader = new DependencyDeclarationIndexReader(),
    ) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function provide(Project $project, iterable $projectFiles): ProjectParseResult
    {
        return $this->reader->read(
            $this->manifestPath,
            $project->configuration->targetPhpVersion,
            $this->expectedManifestHash,
        );
    }
}
