<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Cache;

use Atatusoft\Ppphp\Compiler\CompilationArtifact;
use Atatusoft\Ppphp\Compiler\Manifest\BuildManifest;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final readonly class CachedArtifactBundle
{
    /** @param list<CompilationArtifact> $artifacts */
    public function __construct(
        public array $artifacts,
        public BuildManifest $manifest,
        public string $serializedManifest,
        public DiagnosticBag $diagnostics,
    ) {}
}
