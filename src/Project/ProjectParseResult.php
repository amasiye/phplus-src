<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Frontend\ParsedFile;
use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Support\Path;

final readonly class ProjectParseResult
{
    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param array<string, SourceFile> $sourceFiles
     */
    public function __construct(
        private array $parsedFiles,
        private array $sourceFiles,
        public DiagnosticBag $diagnostics,
    ) {}

    public function isSuccessful(): bool
    {
        return !$this->diagnostics->hasErrors();
    }

    public function parsedFile(string $path): ?ParsedFile
    {
        return $this->parsedFiles[Path::comparisonKey($path)] ?? null;
    }

    public function sourceFile(string $path): ?SourceFile
    {
        return $this->sourceFiles[Path::comparisonKey($path)] ?? null;
    }
}
