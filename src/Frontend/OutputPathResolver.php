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
        if ($source->kind !== FileKind::Phplus) {
            throw new \InvalidArgumentException('Only PHPlus sources have generated PHP output paths.');
        }

        $relativePath = substr($source->relativePath, 0, -strlen('.phplus')) . '.php';

        return Path::join($configuration->outputPath, $relativePath);
    }
}
