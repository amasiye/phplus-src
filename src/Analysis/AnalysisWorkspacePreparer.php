<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Project\ProjectSource;
use Amasiye\Ppphp\Project\ProjectSyntaxChecker;
use Amasiye\Ppphp\Project\SourceSet;
use Amasiye\Ppphp\Semantic\SemanticAnalysisResult;
use Amasiye\Ppphp\Semantic\SemanticAnalyzer;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\GeneratedPhp;
use Amasiye\Ppphp\Transpilation\GeneratedSourceMap;
use Amasiye\Ppphp\Transpilation\PhpLowerer;

final readonly class AnalysisWorkspacePreparer
{
    public function __construct(
        private ProjectSyntaxChecker $syntaxChecker = new ProjectSyntaxChecker(),
        private SemanticAnalyzer $semanticAnalyzer = new SemanticAnalyzer(),
        private PhpLowerer $lowerer = new PhpLowerer(),
    ) {}

    public function prepare(
        Project $project,
        SourceSet $selectedSources,
        ProjectParseResult $selectedParseResult,
        SemanticAnalysisResult $selectedSemanticResult,
    ): AnalysisPreparationResult {
        $diagnostics = new DiagnosticBag();
        $contextParsedFiles = [];
        $contextSourceFiles = [];
        $selectedFiles = [];
        $contextFiles = [];
        $workspace = Path::join($project->configuration->cachePath, 'analysis');

        try {
            $this->guardWorkspace($project, $workspace);
            $this->resetDirectory($workspace);

            foreach ($project->sources as $source) {
                $selected = $selectedSources->contains($source);
                $parseResult = $selected
                    ? $selectedParseResult
                    : $this->syntaxChecker->check($project, new SourceSet([$source]));

                if (!$parseResult->isSuccessful) {
                    continue;
                }

                $semanticResult = $selected
                    ? $selectedSemanticResult
                    : $this->semanticAnalyzer->analyze($parseResult);

                if (!$semanticResult->isSuccessful) {
                    continue;
                }

                $sourceFile = $parseResult->findSourceFile($source->path);
                $parsedFile = $parseResult->findParsedFile($source->path);

                if ($sourceFile === null || $parsedFile === null) {
                    continue;
                }

                if (!$selected) {
                    $key = Path::buildComparisonKey($source->path);
                    $contextParsedFiles[$key] = $parsedFile;
                    $contextSourceFiles[$key] = $sourceFile;
                }

                if ($source->kind === FileKind::Ppp) {
                    $model = $semanticResult->findModel($source->path);

                    if ($model === null) {
                        continue;
                    }

                    $generated = $this->lowerer->lower($parsedFile, $model);
                } else {
                    $generated = new GeneratedPhp(
                        $sourceFile->contents,
                        GeneratedSourceMap::createIdentity($sourceFile),
                        [],
                    );
                }

                $analysisPath = $this->resolveAnalysisPath($workspace, $source, $selected);
                $this->writeFile($analysisPath, $generated->contents);
                $analysisFile = new AnalysisFile(
                    $sourceFile,
                    $analysisPath,
                    $generated->contents,
                    $source->kind,
                    $selected,
                    new AnalysisSourceMap($analysisPath, $generated->contents, $generated->sourceMap),
                );

                if ($selected) {
                    $selectedFiles[] = $analysisFile;
                } else {
                    $contextFiles[] = $analysisFile;
                }
            }

            $stubFiles = $this->copyStubs($project, $workspace);
            [$composerScanFiles, $composerScanDirectories] = $this->resolveComposerContext($project);
            $analysisProject = new AnalysisProject(
                $project->configuration->projectRoot,
                $workspace,
                $selectedFiles,
                $contextFiles,
                $stubFiles,
                $composerScanFiles,
                $composerScanDirectories,
                $project->configuration->targetPhpVersion,
            );
            $this->writeMaps($analysisProject);
        } catch (\Throwable $exception) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::AnalysisWorkspacePreparationFailed,
                Severity::Error,
                'Analysis Workspace Could Not Be Prepared',
                'The compiler could not prepare its isolated static-analysis workspace.',
                help: 'Check that the configured cache path is writable and is not a symbolic link.',
                debug: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            ));
            $analysisProject = null;
        }

        return new AnalysisPreparationResult(
            $analysisProject,
            new ProjectParseResult($contextParsedFiles, $contextSourceFiles, new DiagnosticBag()),
            $diagnostics,
        );
    }

    private function guardWorkspace(Project $project, string $workspace): void
    {
        if (
            !Path::contains($project->configuration->cachePath, $workspace)
            || !Path::contains($project->configuration->projectRoot, $workspace)
            || Path::buildComparisonKey($workspace) === Path::buildComparisonKey($project->configuration->cachePath)
        ) {
            throw new \RuntimeException('The analysis workspace is outside the configured cache root.');
        }
    }

    private function resetDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('The analysis workspace cannot be a symbolic link.');
        }

        if (is_dir($path)) {
            foreach (new \DirectoryIterator($path) as $entry) {
                if (!$entry->isDot()) {
                    $this->removePath($entry->getPathname());
                }
            }
        } elseif (file_exists($path)) {
            throw new \RuntimeException('The analysis workspace path is not a directory.');
        } elseif (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('The analysis workspace could not be created.');
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('An existing analysis artifact could not be removed.');
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $this->removePath($entry->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException('An existing analysis directory could not be removed.');
        }
    }

    private function resolveAnalysisPath(string $workspace, ProjectSource $source, bool $selected): string
    {
        $rootId = substr(hash('sha256', Path::normalize($source->sourceRoot)), 0, 16);
        $relative = $source->kind === FileKind::Ppp
            ? substr($source->relativePath, 0, -4) . '.php'
            : $source->relativePath;

        return Path::join($workspace, $selected ? 'selected' : 'context', $rootId, $relative);
    }

    /** @return list<string> */
    private function copyStubs(Project $project, string $workspace): array
    {
        $paths = [];

        foreach ($project->stubs as $stub) {
            $rootId = substr(hash('sha256', Path::normalize($stub->stubRoot)), 0, 16);
            $relative = Path::resolveRelativeTo($stub->path, $stub->stubRoot);
            $target = Path::join($workspace, 'stubs', $rootId, $relative);
            $contents = file_get_contents($stub->path);

            if ($contents === false) {
                throw new \RuntimeException('A configured stub could not be read.');
            }

            $this->writeFile($target, $contents);
            $paths[] = $target;
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return array{list<string>, list<string>} */
    private function resolveComposerContext(Project $project): array
    {
        $files = [];
        $directories = [];
        $paths = [...$project->composer->projectAutoload->paths, ...$project->composer->dependencyAutoload->paths];

        foreach ($paths as $path) {
            if ($this->overlapsSourceRoot($project, $path)) {
                continue;
            }

            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                $files[] = Path::normalize($path);
            } elseif (is_dir($path) && !is_link($path)) {
                $directories[] = Path::normalize($path);
            }
        }

        $files = array_values(array_unique($files));
        $directories = array_values(array_unique($directories));
        sort($files, SORT_STRING);
        sort($directories, SORT_STRING);

        return [$files, $directories];
    }

    private function overlapsSourceRoot(Project $project, string $path): bool
    {
        foreach ($project->configuration->sourceRoots as $sourceRoot) {
            if (Path::overlaps($sourceRoot, $path)) {
                return true;
            }
        }

        return false;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $directory));
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('File "%s" could not be written.', $path));
        }
    }

    private function writeMaps(AnalysisProject $project): void
    {
        $maps = [];

        foreach ([...$project->selectedFiles, ...$project->contextFiles] as $file) {
            $maps[] = [
                'analysis' => Path::resolveRelativeTo($file->analysisPath, $project->workspaceRoot),
                'source' => $file->sourceFile->displayPath,
                'selected' => $file->selected,
            ];
        }

        $json = json_encode($maps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->writeFile(Path::join($project->workspaceRoot, 'maps.json'), $json . "\n");
    }
}
