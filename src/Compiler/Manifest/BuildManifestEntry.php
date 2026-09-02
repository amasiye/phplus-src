<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Manifest;

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;

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
