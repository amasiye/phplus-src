<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Project\SourceSet;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Support\Path;

final readonly class DeclarationContextCollector
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
    ) {}

    public function collect(Project $project, SourceSet $selectedSources): ProjectParseResult
    {
        $unselected = new SourceSet(array_filter(
            $project->sources->files,
            static fn (ProjectSource $source): bool => !$selectedSources->contains($source),
        ));
        $result = $this->syntaxChecker->check($project, $unselected);

        if ($result->parsedFiles === []) {
            return new ProjectParseResult([], $result->sourceFiles, new DiagnosticBag());
        }

        $analysis = $this->semanticAnalyzer->analyze(new ProjectParseResult(
            $result->parsedFiles,
            $result->sourceFiles,
            new DiagnosticBag(),
        ));
        $invalidSources = [];

        foreach ($analysis->diagnostics->errors as $diagnostic) {
            $span = $diagnostic->primary?->span;

            if ($span === null || !$this->isInvalidDeclarationDiagnostic($diagnostic)) {
                continue;
            }

            $parsedFile = $result->findParsedFile($span->sourceFile->path);

            if ($parsedFile === null || !$this->isDeclarationHeaderSpan($parsedFile, $span->start->offset)) {
                continue;
            }

            $invalidSources[Path::buildComparisonKey($span->sourceFile->path)] = true;
        }

        return new ProjectParseResult(
            array_diff_key($result->parsedFiles, $invalidSources),
            array_diff_key($result->sourceFiles, $invalidSources),
            new DiagnosticBag(),
        );
    }

    private function isInvalidDeclarationDiagnostic(Diagnostic $diagnostic): bool
    {
        return in_array($diagnostic->code, [
            DiagnosticCode::DuplicateTypeParameter,
            DiagnosticCode::UnknownTypeParameter,
            DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
            DiagnosticCode::TypeArgumentDoesNotSatisfyBound,
            DiagnosticCode::GenericTypeArgumentsAreRequired,
            DiagnosticCode::TypeIsNotGeneric,
            DiagnosticCode::GenericDocumentationConflictsWithNativeSyntax,
            DiagnosticCode::InvalidGenericBound,
            DiagnosticCode::InvalidCompositeType,
        ], true);
    }

    private function isDeclarationHeaderSpan(ParsedFile $parsedFile, int $offset): bool
    {
        foreach ($parsedFile->extensionSyntax->genericDeclarations as $declaration) {
            if ($offset >= $declaration->span->start->offset && $offset <= $declaration->span->end->offset) {
                return true;
            }
        }

        return false;
    }
}
