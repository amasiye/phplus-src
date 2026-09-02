<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Interop\Composer\DependencyDeclarationProvenance;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;

final class ProjectParseResult
{
    /**
     * @param array<string, ParsedFile> $parsedFiles
     * @param array<string, SourceFile> $sourceFiles
     * @param list<string> $knownClassPrefixes
     * @param array<string, string> $classAliases alias name to original name
     * @param array<string, DependencyDeclarationProvenance> $classAliasProvenance
     */
    public function __construct(
        public readonly array $parsedFiles,
        public readonly array $sourceFiles,
        public readonly DiagnosticBag $diagnostics,
        public readonly array $knownClassPrefixes = [],
        public readonly array $classAliases = [],
        public readonly array $classAliasProvenance = [],
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
