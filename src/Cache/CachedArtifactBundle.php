<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

use Amasiye\Ppphp\Compiler\CompilationArtifact;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifest;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

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
