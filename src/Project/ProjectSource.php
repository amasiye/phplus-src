<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

final readonly class ProjectSource
{
    public string $path;

    public string $sourceRoot;

    public string $relativePath;

    public string $displayPath;

    public function __construct(
        string $path,
        string $sourceRoot,
        public FileKind $kind,
        ?string $projectRoot = null,
    ) {
        $this->path = Path::normalize($path);
        $this->sourceRoot = Path::normalize($sourceRoot);

        if (!Path::isAbsolute($this->path) || !Path::isAbsolute($this->sourceRoot)) {
            throw new \InvalidArgumentException('Project source paths must be absolute.');
        }

        if (!Path::contains($this->sourceRoot, $this->path)) {
            throw new \InvalidArgumentException('A project source must be contained by its source root.');
        }

        if (!in_array($kind, [FileKind::Php, FileKind::Ppphp], true)) {
            throw new \InvalidArgumentException('A project source must be PHP or ++PHP.');
        }

        $lowerPath = strtolower($this->path);

        if (
            ($kind === FileKind::Php && !str_ends_with($lowerPath, FileKind::PHP_SUFFIX))
            || ($kind === FileKind::Ppphp && !str_ends_with($lowerPath, FileKind::PPPHP_SUFFIX))
        ) {
            throw new \InvalidArgumentException('A project source kind must match its file suffix.');
        }

        $this->relativePath = Path::resolveRelativeTo($this->path, $this->sourceRoot);
        $this->displayPath = Path::resolveRelativeTo($this->path, $projectRoot ?? $this->sourceRoot);
    }
}
