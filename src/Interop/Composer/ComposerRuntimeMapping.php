<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

final readonly class ComposerRuntimeMapping
{
    public function __construct(
        public string $section,
        public string $entry,
        public string $sourcePath,
        public string $expectedPath,
    ) {}
}
