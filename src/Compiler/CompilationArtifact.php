<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler;

use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Project\ProjectSource;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\GeneratedSourceMap;

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
        public readonly ?string $serializedSourceMap = null,
    ) {
        if (!Path::isAbsolute($outputPath) || Path::isAbsolute($relativeOutputPath)) {
            throw new \InvalidArgumentException('Compilation artifacts require an absolute output and a relative output path.');
        }

        if ($sourceMap->sourceFile !== $sourceFile || $sourceMap->generatedLength !== strlen($contents)) {
            throw new \InvalidArgumentException('Compilation artifact source-map identity does not match its contents.');
        }

        if ($serializedSourceMap !== null && !str_ends_with($serializedSourceMap, "\n")) {
            throw new \InvalidArgumentException('A persisted source map must retain its final LF.');
        }
    }

    public string $sourceMapPath {
        get => Path::join('.ppphp/source-maps', $this->relativeOutputPath . '.map.json');
    }
}
