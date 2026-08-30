<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

final class OutputPathResolver
{
    public function resolve(ProjectConfig $configuration, ProjectSource $source): string
    {
        return Path::join($configuration->outputPath, $this->resolveRelative($source));
    }

    public function resolveRelative(ProjectSource $source): string
    {
        return match ($source->kind) {
            FileKind::Ppphp => substr($source->relativePath, 0, -strlen(FileKind::PPPHP_SUFFIX)) . FileKind::PHP_SUFFIX,
            FileKind::Php => $source->relativePath,
            default => throw new \InvalidArgumentException('Only project-owned PHP and ++PHP sources have build output paths.'),
        };
    }
}
