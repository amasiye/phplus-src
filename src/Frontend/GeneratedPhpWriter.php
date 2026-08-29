<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Support\Path;

final class GeneratedPhpWriter
{
    public function write(
        ProjectConfig $configuration,
        string $contents,
        string $outputPath,
    ): BuildResult {
        $diagnostics = new DiagnosticBag();

        if (!Path::contains($configuration->outputPath, $outputPath)) {
            return $this->createFailure(
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
            return $this->createFailure(
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
            return $this->createFailure(
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
            return $this->createFailure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP path could not be validated safely.',
            );
        }

        $temporaryPath = $parent . '/.' . basename($outputPath) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $bytesWritten = @file_put_contents($temporaryPath, $contents, LOCK_EX);

        if ($bytesWritten !== strlen($contents)) {
            @unlink($temporaryPath);

            return $this->createFailure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP bytes could not be written completely.',
            );
        }

        if (!@rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);

            return $this->createFailure(
                $diagnostics,
                $configuration,
                $outputPath,
                'The generated PHP file could not be moved into place.',
            );
        }

        return new BuildResult($outputPath, $diagnostics);
    }

    private function createFailure(
        DiagnosticBag $diagnostics,
        ProjectConfig $configuration,
        string $outputPath,
        string $debugMessage,
    ): BuildResult {
        $displayPath = Path::resolveRelativeTo($outputPath, $configuration->projectRoot);
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
