<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Frontend\ParsedFile;
use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Support\Path;

final class ProjectParseResult
{
    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param array<string, SourceFile> $sourceFiles
     */
    public function __construct(
        private readonly array $parsedFiles,
        private readonly array $sourceFiles,
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
