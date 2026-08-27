<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Support\Path;

final class ProjectCleaner
{
    public function clean(ProjectConfig $configuration, bool $dryRun = false): ProjectCleanupResult
    {
        $diagnostics = new DiagnosticBag();
        $ownedPaths = [$configuration->outputPath, $configuration->cachePath];

        $this->validate($configuration, $diagnostics);

        if ($diagnostics->hasErrors()) {
            return new ProjectCleanupResult([], $diagnostics);
        }

        $paths = [];

        foreach ($ownedPaths as $path) {
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            $paths[] = $path;

            if ($dryRun) {
                continue;
            }

            try {
                $this->remove($path);
            } catch (\RuntimeException $exception) {
                $diagnostics->add(new Diagnostic(
                    DiagnosticCode::ProjectCleanupFailed,
                    Severity::Error,
                    'Project Cleanup Failed',
                    sprintf(
                        'The compiler-owned path "%s" could not be removed.',
                        Path::relativeTo($path, $configuration->projectRoot),
                    ),
                    help: $exception->getMessage(),
                ));
            }
        }

        return new ProjectCleanupResult($paths, $diagnostics);
    }

    private function validate(ProjectConfig $configuration, DiagnosticBag $diagnostics): void
    {
        $ownedPaths = [
            'output' => $configuration->outputPath,
            'cache' => $configuration->cachePath,
        ];

        foreach ($ownedPaths as $name => $path) {
            if (
                !Path::contains($configuration->projectRoot, $path)
                || Path::comparisonKey($configuration->projectRoot) === Path::comparisonKey($path)
                || Path::hasSymlinkAncestor($path, $configuration->projectRoot)
            ) {
                $this->addUnsafeDiagnostic($name, $diagnostics);
            }

            if (Path::contains($path, $configuration->configurationPath)) {
                $this->addOverlapDiagnostic($name, 'configuration', $diagnostics);
            }

            foreach ($configuration->sourceRoots as $sourceRoot) {
                if (Path::overlaps($path, $sourceRoot)) {
                    $this->addOverlapDiagnostic($name, 'source', $diagnostics);
                }
            }

            foreach ($configuration->stubPaths as $stubPath) {
                if (Path::overlaps($path, $stubPath)) {
                    $this->addOverlapDiagnostic($name, 'stubs', $diagnostics);
                }
            }
        }

        if (Path::overlaps($configuration->outputPath, $configuration->cachePath)) {
            $this->addOverlapDiagnostic('output', 'cache', $diagnostics);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to unlink "%s".', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $directory = new \DirectoryIterator($path);

        foreach ($directory as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entryPath = $entry->getPathname();

            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($entryPath)) {
                    throw new \RuntimeException(sprintf('Unable to unlink "%s".', $entryPath));
                }

                continue;
            }

            if ($entry->isDir()) {
                $this->remove($entryPath);
            }
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException(sprintf('Unable to remove directory "%s".', $path));
        }
    }

    private function addUnsafeDiagnostic(string $pathName, DiagnosticBag $diagnostics): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::UnsafeProjectPath,
            Severity::Error,
            'Unsafe Project Path',
            sprintf('The configured %s path is not safe to remove.', $pathName),
        ));
    }

    private function addOverlapDiagnostic(
        string $first,
        string $second,
        DiagnosticBag $diagnostics,
    ): void {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ConfiguredPathsOverlap,
            Severity::Error,
            'Configured Paths Overlap',
            sprintf('The configured %s and %s paths overlap.', $first, $second),
        ));
    }
}
