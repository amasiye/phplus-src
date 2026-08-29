<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;

final readonly class AnalysisFile
{
    public function __construct(
        public SourceFile $sourceFile,
        public string $analysisPath,
        public string $contents,
        public FileKind $kind,
        public bool $selected,
        public AnalysisSourceMap $sourceMap,
    ) {}
}
