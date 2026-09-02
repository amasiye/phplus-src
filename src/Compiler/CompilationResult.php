<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler;

use Atatusoft\Ppphp\Cache\CacheStatistics;
use Atatusoft\Ppphp\Compiler\Enumerations\CompilationFailureKind;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class CompilationResult
{
    /** @param list<CompilationArtifact> $artifacts */
    public function __construct(
        public readonly array $artifacts,
        public readonly ?BuildManifest $manifest,
        public readonly int $staleRemovalCount,
        public readonly bool $committed,
        public readonly ?CompilationFailureKind $failureKind,
        public readonly DiagnosticBag $diagnostics,
        public readonly ?CacheStatistics $cacheStatistics = null,
        public readonly bool $upToDate = false,
    ) {
        if ($committed !== ($manifest !== null && $failureKind === null)) {
            throw new \InvalidArgumentException('Compilation result state is inconsistent.');
        }

        if ($committed && $diagnostics->hasErrors) {
            throw new \InvalidArgumentException('A committed compilation cannot contain error diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->committed && !$this->diagnostics->hasErrors;
    }
}
