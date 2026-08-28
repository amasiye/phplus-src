<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Source\SourceManager;
use Amasiye\Phplus\Support\Path;

final class ExplicitSourceLoader
{
    public function load(ProjectConfig $configuration, ?string $requestedPath): ExplicitSourceLoadResult
    {
        $diagnostics = new DiagnosticBag();

        if ($requestedPath === null || trim($requestedPath) === '') {
            $diagnostics->add($this->error(
                DiagnosticCode::ExplicitSourceFileRequired,
                'Explicit Source File Is Required',
                'Project-wide source discovery is not available in this compiler build.',
                'Supply a .phplus file, for example: phplus check src/Example.phplus',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        $sourcePath = Path::absolute($requestedPath, $configuration->projectRoot);

        if (!Path::contains($configuration->projectRoot, $sourcePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::FileOutsideProjectRoot,
                'File Is Outside Project Root',
                'The requested source file must be inside the project root.',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        if (!file_exists($sourcePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::InputFileDoesNotExist,
                'Input File Does Not Exist',
                sprintf('The requested source file "%s" does not exist.', $requestedPath),
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        if (is_dir($sourcePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::DirectoryCompilationUnavailable,
                'Directory Compilation Is Not Available',
                'Supply one explicit .phplus file.',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        if (!is_file($sourcePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::InputPathNotFile,
                'Input Path Is Not A File',
                sprintf('The requested path "%s" is not a regular file.', $requestedPath),
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        $realSourcePath = realpath($sourcePath);

        if (
            $realSourcePath === false
            || !Path::contains($configuration->projectRoot, Path::normalize($realSourcePath))
        ) {
            $diagnostics->add($this->error(
                DiagnosticCode::FileOutsideProjectRoot,
                'File Is Outside Project Root',
                'The requested source file resolves outside the project root.',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        if (!str_ends_with(strtolower($sourcePath), '.phplus')) {
            $diagnostics->add($this->error(
                DiagnosticCode::UnsupportedSourceFile,
                'Unsupported Source File',
                'The ordinary PHP frontend accepts only .phplus source files.',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        if (!is_readable($sourcePath)) {
            $diagnostics->add($this->error(
                DiagnosticCode::SourceFileNotReadable,
                'Source File Is Not Readable',
                sprintf('The requested source file "%s" cannot be read.', $requestedPath),
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        $sourceRoot = $this->matchingSourceRoot($configuration, $sourcePath);

        if ($sourceRoot === null) {
            $diagnostics->add($this->error(
                DiagnosticCode::SourceFileOutsideConfiguredRoots,
                'Source File Is Outside Configured Roots',
                'The requested source file must be inside a configured source root.',
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        try {
            $sourceFile = (new SourceManager($configuration->projectRoot))->load($sourcePath, FileKind::Phplus);
        } catch (\RuntimeException $exception) {
            $diagnostics->add($this->error(
                DiagnosticCode::SourceFileNotReadable,
                'Source File Is Not Readable',
                sprintf('The requested source file "%s" could not be read.', $requestedPath),
                debug: ['message' => $exception->getMessage()],
            ));

            return new ExplicitSourceLoadResult(null, $diagnostics);
        }

        return new ExplicitSourceLoadResult(
            new ExplicitSource($sourceFile, $sourceRoot),
            $diagnostics,
        );
    }

    private function matchingSourceRoot(ProjectConfig $configuration, string $sourcePath): ?string
    {
        $realSourcePath = realpath($sourcePath);

        if ($realSourcePath === false) {
            return null;
        }

        $realSourcePath = Path::normalize($realSourcePath);
        $matchingRoots = [];

        foreach ($configuration->sourceRoots as $sourceRoot) {
            if (!Path::contains($sourceRoot, $sourcePath)) {
                continue;
            }

            $realSourceRoot = realpath($sourceRoot);

            if ($realSourceRoot === false || !Path::contains(Path::normalize($realSourceRoot), $realSourcePath)) {
                continue;
            }

            $matchingRoots[] = $sourceRoot;
        }

        usort($matchingRoots, static function (string $left, string $right): int {
            $specificity = strlen($right) <=> strlen($left);

            return $specificity !== 0
                ? $specificity
                : Path::comparisonKey($left) <=> Path::comparisonKey($right);
        });

        return $matchingRoots[0] ?? null;
    }

    /** @param array<string, mixed> $debug */
    private function error(
        DiagnosticCode $code,
        string $title,
        string $message,
        ?string $help = null,
        array $debug = [],
    ): Diagnostic {
        return new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            help: $help,
            debug: $debug,
        );
    }
}
