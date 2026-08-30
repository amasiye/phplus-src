<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifest;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifestCodec;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifestEntry;
use Amasiye\Ppphp\Compiler\Manifest\ConfigurationFingerprint;
use Amasiye\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Amasiye\Ppphp\Compiler\Validation\Interfaces\PhpValidator;
use Amasiye\Ppphp\Compiler\Validation\PhpLintValidator;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Project\Enumerations\SelectionKind;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectSelection;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\SourceMapWriter;

final readonly class AtomicBuildCommitter
{
    public function __construct(
        private BuildFilesystem $filesystem = new NativeBuildFilesystem(),
        private BuildManifestCodec $manifests = new BuildManifestCodec(),
        private ConfigurationFingerprint $fingerprints = new ConfigurationFingerprint(),
        private SourceMapWriter $sourceMaps = new SourceMapWriter(),
        private PhpValidator $phpValidator = new PhpLintValidator(),
    ) {}

    /** @param list<CompilationArtifact> $artifacts */
    public function commit(Project $project, ProjectSelection $selection, array $artifacts): BuildCommitResult
    {
        $diagnostics = new DiagnosticBag();
        $configuration = $project->configuration;
        $output = $configuration->outputPath;
        $stage = Path::join(dirname($output), '.ppphp-stage-' . bin2hex(random_bytes(12)));
        $backup = Path::join(dirname($output), '.ppphp-backup-' . bin2hex(random_bytes(12)));
        $manifest = null;
        $previousFiles = [];

        try {
            $this->guardPaths($project, $stage, $backup);
            $this->filesystem->createDirectory(dirname($output));

            if ($this->filesystem->checkExists($output)) {
                if ($this->filesystem->checkIsLink($output) || !$this->filesystem->checkIsDirectory($output)) {
                    throw new BuildOutputException(
                        DiagnosticCode::BuildCouldNotBeStaged,
                        'Build Could Not Be Staged',
                        'The configured output path is not a safe regular directory.',
                    );
                }

                $previousFiles = $this->filesystem->listFiles($output);
            }

            $fingerprint = $this->fingerprints->calculate($project);
            $previousManifest = $selection->kind === SelectionKind::Project
                ? null
                : $this->readPreviousManifest($project);

            if ($previousManifest !== null) {
                $this->validateCompatibility($project, $previousManifest, $fingerprint, $selection);
            }

            if ($selection->kind === SelectionKind::Project || !$this->filesystem->checkExists($output)) {
                $this->filesystem->createDirectory($stage);
            } else {
                $this->filesystem->cloneTree($output, $stage);
            }

            $entries = $this->prepareEntries($project, $selection, $previousManifest, $artifacts, $stage);
            $this->writeArtifacts($stage, $artifacts);
            $completeProject = $selection->kind === SelectionKind::Project
                ? true
                : ($previousManifest === null ? false : $previousManifest->completeProject);
            $manifest = new BuildManifest(
                Compiler::NAME,
                Compiler::VERSION,
                $configuration->targetPhpVersion,
                $fingerprint,
                $completeProject,
                $entries,
            );
            $this->writeManifest($stage, $manifest);
            $this->filesystem->pruneEmptyDirectories($stage);
            $this->validateCandidate($stage, $manifest);

            foreach ($artifacts as $artifact) {
                $diagnostics->addAll($this->phpValidator->validate(
                    $artifact,
                    Path::join($stage, $artifact->relativeOutputPath),
                ));
            }

            if ($diagnostics->hasErrors) {
                $this->discardCandidate($stage);

                return new BuildCommitResult(null, 0, false, $diagnostics);
            }

            $candidateFiles = $this->filesystem->listFiles($stage);
            $staleRemovalCount = count(array_diff($previousFiles, $candidateFiles));
            $commit = $this->commitCandidate($output, $stage, $backup, $manifest, $staleRemovalCount);
            $diagnostics->addAll($commit->diagnostics);

            if (!$commit->committed) {
                $this->discardCandidate($stage);
            }

            return new BuildCommitResult(
                $commit->manifest,
                $commit->staleRemovalCount,
                $commit->committed,
                $diagnostics,
            );
        } catch (BuildOutputException $exception) {
            $this->discardCandidate($stage);
            $diagnostics->add($this->createDiagnostic(
                $exception->diagnosticCode,
                Severity::Error,
                $exception->diagnosticTitle,
                $exception->getMessage(),
                $exception,
                $exception->diagnosticHelp,
            ));
        } catch (\Throwable $exception) {
            $this->discardCandidate($stage);
            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::BuildCouldNotBeStaged,
                Severity::Error,
                'Build Could Not Be Staged',
                'The compiler could not prepare the complete candidate output tree.',
                $exception,
            ));
        }

        return new BuildCommitResult(null, 0, false, $diagnostics);
    }

    private function guardPaths(Project $project, string $stage, string $backup): void
    {
        $configuration = $project->configuration;
        $output = $configuration->outputPath;

        if (
            !Path::contains($configuration->projectRoot, $output)
            || Path::buildComparisonKey($configuration->projectRoot) === Path::buildComparisonKey($output)
            || Path::hasSymlinkAncestor($output, $configuration->projectRoot)
            || !Path::contains($configuration->projectRoot, $stage)
            || !Path::contains($configuration->projectRoot, $backup)
        ) {
            throw new BuildOutputException(
                DiagnosticCode::BuildCouldNotBeStaged,
                'Build Could Not Be Staged',
                'The configured output transaction paths are unsafe.',
            );
        }

        foreach ($configuration->sourceRoots as $sourceRoot) {
            if (Path::overlaps($output, $sourceRoot)) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildCouldNotBeStaged,
                    'Build Could Not Be Staged',
                    'The configured output path overlaps project source.',
                );
            }
        }

        foreach ($configuration->stubPaths as $stubPath) {
            if (Path::overlaps($output, $stubPath)) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildCouldNotBeStaged,
                    'Build Could Not Be Staged',
                    'The configured output path overlaps project stubs.',
                );
            }
        }

        if (
            Path::overlaps($output, $configuration->cachePath)
            || Path::contains($output, $configuration->configurationPath)
        ) {
            throw new BuildOutputException(
                DiagnosticCode::BuildCouldNotBeStaged,
                'Build Could Not Be Staged',
                'The configured output path overlaps compiler or configuration state.',
            );
        }
    }

    private function readPreviousManifest(Project $project): ?BuildManifest
    {
        $path = Path::join($project->configuration->outputPath, '.ppphp/manifest.json');

        if (!$this->filesystem->checkExists($path)) {
            return null;
        }

        if (!$this->filesystem->checkIsFile($path)) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                'The existing build manifest is not a regular file.',
                'Run a pathless `ppphp build` to replace the complete output tree.',
            );
        }

        try {
            return $this->manifests->parse($this->filesystem->readFile($path));
        } catch (\Throwable $exception) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                'The existing build manifest cannot be used for a partial build.',
                'Run a pathless `ppphp build` to replace the complete output tree.',
                $exception,
            );
        }
    }

    private function validateCompatibility(
        Project $project,
        BuildManifest $manifest,
        string $fingerprint,
        ProjectSelection $selection,
    ): void {
        if (
            $manifest->compilerName !== Compiler::NAME
            || $manifest->compilerVersion !== Compiler::VERSION
            || $manifest->targetPhpVersion !== $project->configuration->targetPhpVersion
            || $manifest->configurationFingerprint !== $fingerprint
        ) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestDoesNotMatchConfiguration,
                'Build Manifest Does Not Match Configuration',
                'The existing output was produced by an incompatible compiler or output configuration.',
                'Run a pathless `ppphp build` to replace the complete output tree.',
            );
        }

        foreach ($manifest->files as $entry) {
            if ($this->belongsToSelection($project, $selection, $entry)) {
                continue;
            }

            $outputPath = Path::join($project->configuration->outputPath, $entry->output);

            if (
                !$this->filesystem->checkIsFile($outputPath)
                || 'sha256:' . hash('sha256', $this->filesystem->readFile($outputPath)) !== $entry->outputHash
            ) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildOutputHasBeenModified,
                    'Build Output Has Been Modified',
                    sprintf('Manifest-owned output "%s" no longer matches its recorded hash.', $entry->output),
                    'Run a complete pathless build to regenerate compiler-owned output.',
                );
            }

            $this->validatePersistedMap($project->configuration->outputPath, $entry);
        }
    }

    /**
     * @param list<CompilationArtifact> $artifacts
     * @return list<BuildManifestEntry>
     */
    private function prepareEntries(
        Project $project,
        ProjectSelection $selection,
        ?BuildManifest $previousManifest,
        array $artifacts,
        string $stage,
    ): array {
        $entries = [];

        if ($selection->kind !== SelectionKind::Project && $previousManifest !== null) {
            foreach ($previousManifest->files as $entry) {
                if ($this->belongsToSelection($project, $selection, $entry)) {
                    $this->filesystem->remove(Path::join($stage, $entry->output));
                    $this->filesystem->remove(Path::join($stage, $entry->sourceMap));
                    continue;
                }

                $entries[] = $entry;
            }
        }

        foreach ($artifacts as $artifact) {
            $entries[] = BuildManifestEntry::createFromArtifact($artifact);
        }

        $sources = [];
        $outputs = [];

        foreach ($entries as $entry) {
            $source = strtolower(Path::normalize($entry->source));
            $output = strtolower(Path::normalize($entry->output));

            if (isset($sources[$source]) || isset($outputs[$output])) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildManifestIsInvalid,
                    'Build Manifest Is Invalid',
                    'The partial build would create duplicate manifest source or output paths.',
                    'Run a pathless `ppphp build` to replace the complete output tree.',
                );
            }

            $sources[$source] = true;
            $outputs[$output] = true;
        }

        return $entries;
    }

    /** @param list<CompilationArtifact> $artifacts */
    private function writeArtifacts(string $stage, array $artifacts): void
    {
        foreach ($artifacts as $artifact) {
            try {
                $this->filesystem->writeFile(
                    Path::join($stage, $artifact->relativeOutputPath),
                    $artifact->contents,
                    $artifact->mode,
                );
            } catch (\Throwable $exception) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildCouldNotBeStaged,
                    'Build Could Not Be Staged',
                    sprintf('Output "%s" could not be written into the candidate build.', $artifact->relativeOutputPath),
                    previous: $exception,
                );
            }

            try {
                $map = $this->sourceMaps->serialize($artifact);
                $this->filesystem->writeFile(Path::join($stage, $artifact->sourceMapPath), $map);
            } catch (\Throwable $exception) {
                throw new BuildOutputException(
                    DiagnosticCode::SourceMapCouldNotBeWritten,
                    'Source Map Could Not Be Written',
                    sprintf('The source map for "%s" could not be written.', $artifact->relativeOutputPath),
                    previous: $exception,
                );
            }
        }
    }

    private function writeManifest(string $stage, BuildManifest $manifest): void
    {
        try {
            $this->filesystem->writeFile(
                Path::join($stage, '.ppphp/manifest.json'),
                $this->manifests->serialize($manifest),
            );
        } catch (\Throwable $exception) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                'The candidate build manifest could not be written.',
                previous: $exception,
            );
        }
    }

    private function validateCandidate(string $stage, BuildManifest $manifest): void
    {
        foreach ($manifest->files as $entry) {
            $output = Path::join($stage, $entry->output);

            if (
                !Path::contains($stage, $output)
                || !$this->filesystem->checkIsFile($output)
                || 'sha256:' . hash('sha256', $this->filesystem->readFile($output)) !== $entry->outputHash
            ) {
                throw new BuildOutputException(
                    DiagnosticCode::BuildManifestIsInvalid,
                    'Build Manifest Is Invalid',
                    sprintf('Candidate output "%s" is missing or does not match its manifest hash.', $entry->output),
                );
            }

            $this->validatePersistedMap($stage, $entry);
        }

        $serialized = $this->filesystem->readFile(Path::join($stage, '.ppphp/manifest.json'));
        $parsed = $this->manifests->parse($serialized);

        if ($this->manifests->serialize($parsed) !== $serialized) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                'The candidate build manifest is not canonically serialized.',
            );
        }
    }

    private function validatePersistedMap(string $root, BuildManifestEntry $entry): void
    {
        $map = Path::join($root, $entry->sourceMap);
        $output = Path::join($root, $entry->output);

        if (!Path::contains(Path::join($root, '.ppphp/source-maps'), $map) || !$this->filesystem->checkIsFile($map)) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                sprintf('Source map "%s" is missing or outside compiler metadata.', $entry->sourceMap),
            );
        }

        $contents = $this->filesystem->readFile($output);

        try {
            $this->sourceMaps->parseAndValidate(
                $this->filesystem->readFile($map),
                $entry->source,
                $entry->output,
                $entry->sourceHash,
                $entry->outputHash,
                strlen($contents),
            );
        } catch (\Throwable $exception) {
            throw new BuildOutputException(
                DiagnosticCode::BuildManifestIsInvalid,
                'Build Manifest Is Invalid',
                sprintf('Source map "%s" is invalid.', $entry->sourceMap),
                previous: $exception,
            );
        }
    }

    private function belongsToSelection(
        Project $project,
        ProjectSelection $selection,
        BuildManifestEntry $entry,
    ): bool {
        if ($selection->kind === SelectionKind::Project) {
            return true;
        }

        $selectedPath = $selection->selectedPath;

        if ($selectedPath === null) {
            throw new \LogicException('A partial project selection requires a selected path.');
        }

        $sourcePath = Path::join($project->configuration->projectRoot, $entry->source);

        return $selection->kind === SelectionKind::Directory
            ? Path::contains($selectedPath, $sourcePath)
            : Path::buildComparisonKey($selectedPath) === Path::buildComparisonKey($sourcePath);
    }

    private function commitCandidate(
        string $output,
        string $stage,
        string $backup,
        BuildManifest $manifest,
        int $staleRemovalCount,
    ): BuildCommitResult {
        $diagnostics = new DiagnosticBag();
        $hasBackup = false;

        try {
            if ($this->filesystem->checkExists($output)) {
                $this->filesystem->move($output, $backup);
                $hasBackup = true;
            }

            $this->filesystem->move($stage, $output);
        } catch (\Throwable $commitException) {
            if ($hasBackup) {
                try {
                    $this->filesystem->move($backup, $output);
                } catch (\Throwable $restoreException) {
                    $diagnostics->add($this->createDiagnostic(
                        DiagnosticCode::PreviousBuildCouldNotBeRestored,
                        Severity::Error,
                        'Previous Build Could Not Be Restored',
                        'The candidate could not be committed and the previous output could not be restored.',
                        $restoreException,
                    ));

                    return new BuildCommitResult(null, 0, false, $diagnostics);
                }
            }

            $diagnostics->add($this->createDiagnostic(
                DiagnosticCode::BuildCouldNotBeCommitted,
                Severity::Error,
                'Build Could Not Be Committed',
                'The validated candidate output could not replace the current output tree.',
                $commitException,
            ));

            return new BuildCommitResult(null, 0, false, $diagnostics);
        }

        if ($hasBackup) {
            try {
                $this->filesystem->remove($backup);
            } catch (\Throwable $exception) {
                $diagnostics->add($this->createDiagnostic(
                    DiagnosticCode::PreviousBuildBackupCouldNotBeRemoved,
                    Severity::Warning,
                    'Previous Build Backup Could Not Be Removed',
                    'The new build committed successfully, but its previous-output backup could not be removed.',
                    $exception,
                ));
            }
        }

        return new BuildCommitResult($manifest, $staleRemovalCount, true, $diagnostics);
    }

    private function discardCandidate(string $stage): void
    {
        try {
            if ($this->filesystem->checkExists($stage)) {
                $this->filesystem->remove($stage);
            }
        } catch (\Throwable) {
        }
    }

    private function createDiagnostic(
        DiagnosticCode $code,
        Severity $severity,
        string $title,
        string $message,
        \Throwable $exception,
        ?string $help = null,
    ): Diagnostic {
        return new Diagnostic(
            $code,
            $severity,
            $title,
            $message,
            help: $help ?? ($severity === Severity::Error ? 'Run again with --debug for transaction details.' : null),
            debug: [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }
}
