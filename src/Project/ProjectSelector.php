<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Project;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;

final class ProjectSelector
{
    public function select(Project $project, ?string $requestedPath, SelectionMode $mode): ProjectSelectionResult
    {
        $diagnostics = new DiagnosticBag();

        if ($requestedPath === null || trim($requestedPath) === '') {
            if ($mode === SelectionMode::DumpAst) {
                return $this->createFailure(
                    $diagnostics,
                    DiagnosticCode::ExplicitSourceFileRequired,
                    'Explicit Source File Is Required',
                'The dump:ast command requires one project-owned PHP or ++PHP file.',
                );
            }

            $analysis = $project->sources;

            return new ProjectSelectionResult(new ProjectSelection(
                $analysis,
                $mode === SelectionMode::Build ? $analysis : new SourceSet(),
            ), $diagnostics);
        }

        $path = Path::resolveAbsolute($requestedPath, $project->configuration->projectRoot);

        if (!Path::contains($project->configuration->projectRoot, $path)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::FileOutsideProjectRoot,
                'Path Is Outside Project Root',
                'The requested path must be inside the project root.',
            );
        }

        if ($this->isExcluded($project, $path)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::SelectedPathExcluded,
                'Selected Path Is Excluded',
                sprintf('The requested path "%s" is excluded from project sources.', $requestedPath),
            );
        }

        if (!file_exists($path) && !is_link($path)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::InputFileDoesNotExist,
                'Input Path Does Not Exist',
                sprintf('The requested path "%s" does not exist.', $requestedPath),
            );
        }

        if (is_dir($path)) {
            if ($mode === SelectionMode::DumpAst) {
                return $this->createFailure(
                    $diagnostics,
                    DiagnosticCode::InputPathNotFile,
                    'Input Path Is Not A File',
                    'The dump:ast command accepts one file, not a directory.',
                );
            }

            if (
                (is_link($path) && !$this->isConfiguredSourceRoot($project, $path))
                || !$this->isWithinSourceRoot($project, $path)
            ) {
                return $this->createOutsideRootsFailure($diagnostics);
            }

            $analysis = $project->sources->filterBeneath($path);

            return new ProjectSelectionResult(new ProjectSelection(
                $analysis,
                $mode === SelectionMode::Build ? $analysis : new SourceSet(),
            ), $diagnostics);
        }

        if (!is_file($path)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::InputPathNotFile,
                'Input Path Is Not A File',
                sprintf('The requested path "%s" is not a regular file.', $requestedPath),
            );
        }

        if (!is_readable($path)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::SelectedPathNotReadable,
                'Selected Path Is Not Readable',
                sprintf('The requested source file "%s" cannot be read.', $requestedPath),
            );
        }

        $realPath = realpath($path);

        if ($realPath === false || !Path::contains($project->configuration->projectRoot, Path::normalize($realPath))) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::FileOutsideProjectRoot,
                'File Is Outside Project Root',
                'The requested source file resolves outside the project root.',
            );
        }

        $lowerPath = strtolower($path);

        if (!str_ends_with($lowerPath, FileKind::PHP_SUFFIX) && !str_ends_with($lowerPath, FileKind::PPPHP_SUFFIX)) {
            return $this->createFailure(
                $diagnostics,
                DiagnosticCode::UnsupportedSourceFile,
                'Unsupported Source File',
                sprintf('Project source files must use the %s or %s suffix.', FileKind::PHP_SUFFIX, FileKind::PPPHP_SUFFIX),
            );
        }

        $source = $project->sources->find($path);

        if ($source === null) {
            return $this->createOutsideRootsFailure($diagnostics);
        }

        $selected = new SourceSet([$source]);

        return new ProjectSelectionResult(new ProjectSelection(
            $selected,
            $mode === SelectionMode::Build ? $selected : new SourceSet(),
        ), $diagnostics);
    }

    private function isWithinSourceRoot(Project $project, string $path): bool
    {
        foreach ($project->configuration->sourceRoots as $sourceRoot) {
            if (Path::contains($sourceRoot, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isConfiguredSourceRoot(Project $project, string $path): bool
    {
        foreach ($project->configuration->sourceRoots as $sourceRoot) {
            if (Path::buildComparisonKey($sourceRoot) === Path::buildComparisonKey($path)) {
                return true;
            }
        }

        return false;
    }

    private function isExcluded(Project $project, string $path): bool
    {
        foreach ($project->configuration->excludedPaths as $excludedPath) {
            if (Path::contains($excludedPath, $path)) {
                return true;
            }
        }

        return false;
    }

    private function createOutsideRootsFailure(DiagnosticBag $diagnostics): ProjectSelectionResult
    {
        return $this->createFailure(
            $diagnostics,
            DiagnosticCode::SourceFileOutsideConfiguredRoots,
            'Selected Path Is Outside Configured Source Roots',
            'The requested path must be owned by a configured source root.',
        );
    }

    private function createFailure(
        DiagnosticBag $diagnostics,
        DiagnosticCode $code,
        string $title,
        string $message,
        ?string $help = null,
    ): ProjectSelectionResult {
        $diagnostics->add(new Diagnostic($code, Severity::Error, $title, $message, help: $help));

        return new ProjectSelectionResult(null, $diagnostics);
    }
}
