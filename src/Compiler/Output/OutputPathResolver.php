<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

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
