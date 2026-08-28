<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final readonly class ExplicitSource
{
    public function __construct(
        public SourceFile $sourceFile,
        public string $sourceRoot,
    ) {
        if (!Path::contains($sourceRoot, $sourceFile->path)) {
            throw new \InvalidArgumentException('The source file must be contained by its source root.');
        }

        if ($sourceFile->kind !== FileKind::Phplus || !str_ends_with(strtolower($sourceFile->path), '.phplus')) {
            throw new \InvalidArgumentException('An explicit frontend source must be a .phplus file.');
        }
    }
}
