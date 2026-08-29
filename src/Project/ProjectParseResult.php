<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;

final class ProjectParseResult
{
    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param array<string, SourceFile> $sourceFiles
     */
    public function __construct(
        public readonly array $parsedFiles,
        public readonly array $sourceFiles,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }

    public function findParsedFile(string $path): ?ParsedFile
    {
        return $this->parsedFiles[Path::buildComparisonKey($path)] ?? null;
    }

    public function findSourceFile(string $path): ?SourceFile
    {
        return $this->sourceFiles[Path::buildComparisonKey($path)] ?? null;
    }
}
