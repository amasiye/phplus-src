<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Support\Path;

final class ComposerConfigurationWriter
{
    public function write(ComposerRuntimeProjection $projection, string $projectRoot): DiagnosticBag
    {
        $diagnostics = new DiagnosticBag();
        $path = $projection->configurationPath;

        if (!$projection->isSuccessful || $path === null || $projection->projectedContents === null || $projection->originalContents === null) {
            throw new \LogicException('Only a successful Composer runtime projection can be written.');
        }

        if (!Path::contains($projectRoot, $path) || is_link($path) || Path::hasSymlinkAncestor($path, $projectRoot)) {
            $this->addWriteError($diagnostics, 'The root composer.json is not a safe writable project path.');

            return $diagnostics;
        }

        $current = @file_get_contents($path);

        if ($current === false) {
            $this->addWriteError($diagnostics, 'The root composer.json could not be read before writing.');

            return $diagnostics;
        }

        if ($current !== $projection->originalContents) {
            $diagnostics->add(new Diagnostic(
                DiagnosticCode::ComposerRuntimeMappingConflictsWithBuildOutput,
                'The root composer.json changed after it was read; no update was written.',
                help: 'Run ppphp composer:configure again against the current file.',
            ));

            return $diagnostics;
        }

        if (!$projection->isChanged) {
            return $diagnostics;
        }

        try {
            $temporaryPath = $path . '.ppphp-' . bin2hex(random_bytes(8)) . '.tmp';
        } catch (\Throwable $exception) {
            $this->addWriteError($diagnostics, 'A secure temporary Composer configuration path could not be created.', $exception);

            return $diagnostics;
        }

        $permissions = @fileperms($path);

        if (@file_put_contents($temporaryPath, $projection->projectedContents, LOCK_EX) === false) {
            $this->addWriteError($diagnostics, 'The projected Composer configuration could not be written.');
            @unlink($temporaryPath);

            return $diagnostics;
        }

        if ($permissions !== false) {
            @chmod($temporaryPath, $permissions & 0777);
        }

        if (!@rename($temporaryPath, $path)) {
            $this->addWriteError($diagnostics, 'The projected Composer configuration could not replace composer.json atomically.');
            @unlink($temporaryPath);
        }

        return $diagnostics;
    }

    private function addWriteError(
        DiagnosticBag $diagnostics,
        string $message,
        ?\Throwable $exception = null,
    ): void {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ComposerConfigurationCouldNotBeUpdated,
            $message,
            debug: $exception === null ? [] : [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ));
    }
}
