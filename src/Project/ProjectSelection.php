<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Project\Enumerations\SelectionKind;
use Amasiye\Ppphp\Support\Path;

final readonly class ProjectSelection
{
    public function __construct(
        public SelectionKind $kind,
        ?string $selectedPath,
        public SourceSet $analysisSources,
        public SourceSet $outputSources,
    ) {
        if (($kind === SelectionKind::Project) !== ($selectedPath === null)) {
            throw new \InvalidArgumentException('Only a complete-project selection omits its selected path.');
        }

        if ($selectedPath !== null && !Path::isAbsolute($selectedPath)) {
            throw new \InvalidArgumentException('A selected project path must be absolute.');
        }

        $this->selectedPath = $selectedPath === null ? null : Path::normalize($selectedPath);
    }

    public readonly ?string $selectedPath;
}
