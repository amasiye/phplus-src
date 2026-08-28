<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Stub;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Support\Path;

final class StubLoader
{
    public function load(ProjectConfig $configuration): StubLoadResult
    {
        $diagnostics = new DiagnosticBag();
        $files = [];

        foreach ($configuration->stubPaths as $stubRoot) {
            if (!file_exists($stubRoot)) {
                $this->addInvalidPathDiagnostic($diagnostics, $configuration, $stubRoot, 'The configured stub directory does not exist.');
                continue;
            }

            $realRoot = realpath($stubRoot);

            if (
                !is_dir($stubRoot)
                || is_link($stubRoot)
                || $realRoot === false
                || !Path::contains($configuration->projectRoot, Path::normalize($realRoot))
            ) {
                $this->addInvalidPathDiagnostic($diagnostics, $configuration, $stubRoot, 'The configured stub path must be a directory inside the project.');
                continue;
            }

            try {
                $this->walk($stubRoot, Path::normalize($realRoot), $stubRoot, $files);
            } catch (\UnexpectedValueException $exception) {
                $this->addInvalidPathDiagnostic($diagnostics, $configuration, $stubRoot, $exception->getMessage());
            }
        }

        if ($diagnostics->hasErrors) {
            return new StubLoadResult(null, $diagnostics);
        }

        return new StubLoadResult(new StubRepository($files), $diagnostics);
    }

    /** @param array<string, StubFile> $files */
    private function walk(string $stubRoot, string $realRoot, string $directory, array &$files): void
    {
        $entries = [];

        foreach (new \DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot()) {
                $entries[] = Path::normalize($entry->getPathname());
            }
        }

        sort($entries, SORT_STRING);

        foreach ($entries as $path) {
            if (is_dir($path)) {
                if (!is_link($path)) {
                    $this->walk($stubRoot, $realRoot, $path, $files);
                }

                continue;
            }

            if (!is_file($path) || !str_ends_with(strtolower($path), '.stub.php')) {
                continue;
            }

            $realPath = realpath($path);

            if ($realPath === false || !Path::contains($realRoot, Path::normalize($realPath))) {
                continue;
            }

            $files[Path::buildComparisonKey(Path::normalize($realPath))] ??= new StubFile($path, $stubRoot);
        }
    }

    private function addInvalidPathDiagnostic(
        DiagnosticBag $diagnostics,
        ProjectConfig $configuration,
        string $path,
        string $reason,
    ): void {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ConfiguredStubPathInvalid,
            Severity::Error,
            'Configured Stub Path Is Invalid',
            sprintf('The configured stub path "%s" could not be loaded.', Path::resolveRelativeTo($path, $configuration->projectRoot)),
            debug: ['reason' => $reason],
        ));
    }

}
