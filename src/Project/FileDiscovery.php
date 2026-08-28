<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final class FileDiscovery
{
    public function discover(ProjectConfig $configuration): FileDiscoveryResult
    {
        $diagnostics = new DiagnosticBag();
        $sources = [];
        $roots = $configuration->sourceRoots;

        usort($roots, static function (string $left, string $right): int {
            return (strlen($right) <=> strlen($left))
                ?: (Path::comparisonKey($left) <=> Path::comparisonKey($right));
        });

        foreach ($roots as $sourceRoot) {
            if (is_link($sourceRoot)) {
                $this->addFailure($diagnostics, $configuration, $sourceRoot, 'A configured source root cannot be a symbolic link.');
                continue;
            }

            $realRoot = realpath($sourceRoot);

            if ($realRoot === false) {
                $this->addFailure($diagnostics, $configuration, $sourceRoot, 'The source root could not be resolved.');
                continue;
            }

            try {
                $this->walk(
                    $configuration,
                    $sourceRoot,
                    Path::normalize($realRoot),
                    $sourceRoot,
                    $sources,
                );
            } catch (\UnexpectedValueException $exception) {
                $this->addFailure($diagnostics, $configuration, $sourceRoot, $exception->getMessage());
            }
        }

        if ($diagnostics->hasErrors()) {
            return new FileDiscoveryResult(null, $diagnostics);
        }

        return new FileDiscoveryResult(new SourceSet($sources), $diagnostics);
    }

    /** @param array<string, ProjectSource> $sources */
    private function walk(
        ProjectConfig $configuration,
        string $sourceRoot,
        string $realRoot,
        string $directory,
        array &$sources,
    ): void {
        $entries = [];

        foreach (new \DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot()) {
                $entries[] = Path::normalize($entry->getPathname());
            }
        }

        usort($entries, static fn (string $left, string $right): int =>
            Path::comparisonKey($left) <=> Path::comparisonKey($right));

        foreach ($entries as $path) {
            if ($this->isExcluded($configuration, $path)) {
                continue;
            }

            if (is_dir($path)) {
                if (!is_link($path)) {
                    $this->walk($configuration, $sourceRoot, $realRoot, $path, $sources);
                }

                continue;
            }

            $kind = $this->sourceKind($path);

            if ($kind === null || !is_file($path)) {
                continue;
            }

            $realPath = realpath($path);

            if ($realPath === false) {
                continue;
            }

            $realPath = Path::normalize($realPath);

            if (!Path::contains($realRoot, $realPath) || $this->isExcluded($configuration, $realPath)) {
                continue;
            }

            $physicalKey = Path::comparisonKey($realPath);
            $existing = $sources[$physicalKey] ?? null;

            if (
                $existing === null
                || (
                    Path::comparisonKey($existing->sourceRoot) === Path::comparisonKey($sourceRoot)
                    && is_link($existing->path)
                    && !is_link($path)
                )
            ) {
                $sources[$physicalKey] = new ProjectSource(
                    $path,
                    $sourceRoot,
                    $kind,
                    $configuration->projectRoot,
                );
            }
        }
    }

    private function sourceKind(string $path): ?FileKind
    {
        $lower = strtolower($path);

        if (str_ends_with($lower, '.phplus')) {
            return FileKind::Phplus;
        }

        return str_ends_with($lower, '.php') ? FileKind::Php : null;
    }

    private function isExcluded(ProjectConfig $configuration, string $path): bool
    {
        foreach ([...$configuration->excludedPaths, ...$configuration->stubPaths] as $excludedPath) {
            if (Path::contains($excludedPath, $path)) {
                return true;
            }
        }

        return false;
    }

    private function addFailure(
        DiagnosticBag $diagnostics,
        ProjectConfig $configuration,
        string $path,
        string $reason,
    ): void {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ProjectSourceDiscoveryFailed,
            Severity::Error,
            'Project Source Discovery Failed',
            sprintf('Sources could not be discovered beneath "%s".', Path::relativeTo($path, $configuration->projectRoot)),
            debug: ['reason' => $reason],
        ));
    }
}
