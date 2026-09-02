<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Interop\Composer\Declaration\DependencyDeclarationProvider;
use Amasiye\Ppphp\Interop\Composer\Declaration\InstalledComposerDeclarationProvider;
use Amasiye\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
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
        private DependencyDeclarationProvider $composerDependencies = new InstalledComposerDeclarationProvider(),
        private PhpSignaturePackageLoader $phpSignatures = new PhpSignaturePackageLoader(),
    ) {}

    public function collect(
        Project $project,
        SourceSet $selectedSources,
        ?ProjectParseResult $selectedResult = null,
        ?DependencyDeclarationProvider $dependencyProvider = null,
    ): ProjectParseResult
    {
        $unselected = new SourceSet(array_filter(
            $project->sources->files,
            static fn (ProjectSource $source): bool => !$selectedSources->contains($source),
        ));
        $result = $this->syntaxChecker->check($project, $unselected);

        $invalidSources = [];

        if ($result->parsedFiles !== []) {
            $analysis = $this->semanticAnalyzer->analyze(new ProjectParseResult(
                $result->parsedFiles,
                $result->sourceFiles,
                new DiagnosticBag(),
            ));

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
        }

        $contextFiles = array_diff_key($result->parsedFiles, $invalidSources);
        $contextSources = array_diff_key($result->sourceFiles, $invalidSources);
        $selectedFiles = $selectedResult === null ? [] : array_values($selectedResult->parsedFiles);
        $dependencies = ($dependencyProvider ?? $this->composerDependencies)->provide(
            $project,
            [
                ...$selectedFiles,
                ...array_values($contextFiles),
            ],
        );
        $platform = $this->phpSignatures->load(
            $project->configuration->targetPhpVersion,
            [
                ...$selectedFiles,
                ...array_values($contextFiles),
                ...array_values($dependencies->parsedFiles),
            ],
        );
        $diagnostics = new DiagnosticBag();
        $diagnostics->addAll($dependencies->diagnostics);
        $diagnostics->addAll($platform->diagnostics);

        return new ProjectParseResult(
            array_replace($contextFiles, $dependencies->parsedFiles, $platform->parsedFiles),
            array_replace($contextSources, $dependencies->sourceFiles, $platform->sourceFiles),
            $diagnostics,
            $dependencies->knownClassPrefixes,
            $dependencies->classAliases,
            $dependencies->classAliasProvenance,
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
