<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

final readonly class DependencySourceCandidate
{
    public function __construct(
        public string $path,
        public ComposerPackage $package,
        public string $autoloadForm,
        public int $includeDepth = 0,
    ) {}
}
