<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Config\ProjectConfig;
use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Support\Path;

final class SourcePreservingPhpBuilder
{
    public function build(ProjectConfig $configuration, ExplicitSource $source): BuildResult
    {
        $diagnostics = new DiagnosticBag();
        $relativePath = Path::relativeTo($source->sourceFile->path, $source->sourceRoot);
        $phpRelativePath = substr($relativePath, 0, -strlen('.phplus')) . '.php';
        $outputPath = Path::join($configuration->outputPath, $phpRelativePath);

        if (
            !Path::contains($configuration->outputPath, $outputPath)
            || Path::comparisonKey($source->sourceFile->path) === Path::comparisonKey($outputPath)
        ) {
            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP path is not a safe compiler-owned output path.',
            );
        }

        $parent = dirname($outputPath);

        if (
            is_link($configuration->outputPath)
            || Path::hasSymlinkAncestor($outputPath, $configuration->outputPath)
        ) {
            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP path passes through a symbolic link.',
            );
        }

        if (
            (file_exists($parent) && !is_dir($parent))
            || (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent))
        ) {
            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The output directory could not be created.',
            );
        }

        $realOutputRoot = realpath($configuration->outputPath);
        $realParent = realpath($parent);

        if (
            $realOutputRoot === false
            || $realParent === false
            || !Path::contains(Path::normalize($realOutputRoot), Path::normalize($realParent))
            || is_link($outputPath)
        ) {
            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP path could not be validated safely.',
            );
        }

        $temporaryPath = $parent . '/.' . basename($outputPath) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $bytesWritten = @file_put_contents(
            $temporaryPath,
            $source->sourceFile->contents,
            LOCK_EX,
        );

        if ($bytesWritten !== $source->sourceFile->length()) {
            @unlink($temporaryPath);

            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP bytes could not be written completely.',
            );
        }

        if (!@rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);

            return $this->failure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP file could not be moved into place.',
            );
        }

        return new BuildResult($outputPath, $diagnostics);
    }

    private function failure(
        DiagnosticBag $diagnostics,
        ProjectConfig $configuration,
        string $outputPath,
        string $debugMessage,
    ): BuildResult {
        $displayPath = Path::relativeTo($outputPath, $configuration->projectRoot);
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::GeneratedPhpCouldNotBeWritten,
            Severity::Error,
            'Generated PHP Could Not Be Written',
            sprintf('The generated PHP file "%s" could not be written.', $displayPath),
            debug: ['reason' => $debugMessage],
        ));

        return new BuildResult(null, $diagnostics);
    }
}
