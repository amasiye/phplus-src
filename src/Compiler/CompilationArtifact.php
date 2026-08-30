<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler;

use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;

final class CompilationArtifact
{
    public function __construct(
        public readonly ProjectSource $projectSource,
        public readonly SourceFile $sourceFile,
        public readonly OutputOperation $operation,
        public readonly string $outputPath,
        public readonly string $relativeOutputPath,
        public readonly string $contents,
        public readonly GeneratedSourceMap $sourceMap,
        public readonly string $sourceHash,
        public readonly string $outputHash,
        public readonly ?int $mode,
    ) {
        if (!Path::isAbsolute($outputPath) || Path::isAbsolute($relativeOutputPath)) {
            throw new \InvalidArgumentException('Compilation artifacts require an absolute output and a relative output path.');
        }

        if ($sourceMap->sourceFile !== $sourceFile || $sourceMap->generatedLength !== strlen($contents)) {
            throw new \InvalidArgumentException('Compilation artifact source-map identity does not match its contents.');
        }
    }

    public string $sourceMapPath {
        get => Path::join('.ppphp/source-maps', $this->relativeOutputPath . '.map.json');
    }
}
