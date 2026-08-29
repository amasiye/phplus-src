<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

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
