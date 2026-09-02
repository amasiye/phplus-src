<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

final readonly class DependencyDeclarationProvenance
{
    public function __construct(
        public string $packageName,
        public ?string $packageVersion,
        public ?string $packageReference,
        public string $packageRelativePath,
        public string $autoloadForm,
        public int $declarationOrder,
        public bool $conditional = false,
        public int $staticIncludeCount = 0,
    ) {}
}
