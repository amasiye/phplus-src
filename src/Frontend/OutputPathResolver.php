<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Project\ProjectSource;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final class OutputPathResolver
{
    public function resolve(ProjectConfig $configuration, ProjectSource $source): string
    {
        if ($source->kind !== FileKind::Ppp) {
            throw new \InvalidArgumentException('Only ++PHP sources have generated PHP output paths.');
        }

        $relativePath = substr($source->relativePath, 0, -strlen('.ppp')) . '.php';

        return Path::join($configuration->outputPath, $relativePath);
    }
}
