<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Manifest;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Source\Enumerations\FileKind;

final readonly class BuildManifestEntry
{
    public function __construct(
        public string $source,
        public string $output,
        public FileKind $sourceKind,
        public OutputOperation $operation,
        public string $sourceHash,
        public string $outputHash,
        public string $sourceMap,
        public ?string $mode,
    ) {}

    public static function createFromArtifact(CompilationArtifact $artifact): self
    {
        return new self(
            $artifact->sourceFile->displayPath,
            $artifact->relativeOutputPath,
            $artifact->projectSource->kind,
            $artifact->operation,
            $artifact->sourceHash,
            $artifact->outputHash,
            $artifact->sourceMapPath,
            $artifact->mode === null ? null : sprintf('%04o', $artifact->mode & 0777),
        );
    }
}
