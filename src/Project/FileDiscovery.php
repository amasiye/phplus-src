<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final class FileDiscovery
{
    public function discover(ProjectConfig $configuration): FileDiscoveryResult
    {
        $diagnostics = new DiagnosticBag();
        $sources = [];
        $roots = $configuration->sourceRoots;

        usort($roots, static function (string $left, string $right): int {
            return (strlen($right) <=> strlen($left))
                ?: (Path::buildComparisonKey($left) <=> Path::buildComparisonKey($right));
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

        if ($diagnostics->hasErrors) {
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
            Path::buildComparisonKey($left) <=> Path::buildComparisonKey($right));

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

            $kind = $this->resolveSourceKind($path);

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

            $physicalKey = Path::buildComparisonKey($realPath);
            $existing = $sources[$physicalKey] ?? null;

            if (
                $existing === null
                || (
                    Path::buildComparisonKey($existing->sourceRoot) === Path::buildComparisonKey($sourceRoot)
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

    private function resolveSourceKind(string $path): ?FileKind
    {
        $lower = strtolower($path);

        if (str_ends_with($lower, FileKind::PPPHP_SUFFIX)) {
            return FileKind::Ppphp;
        }

        return str_ends_with($lower, FileKind::PHP_SUFFIX) ? FileKind::Php : null;
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
            sprintf('Sources could not be discovered beneath "%s".', Path::resolveRelativeTo($path, $configuration->projectRoot)),
            debug: ['reason' => $reason],
        ));
    }
}
