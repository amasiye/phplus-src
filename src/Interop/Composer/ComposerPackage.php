<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

final readonly class ComposerPackage
{
    public function __construct(
        public string $name,
        public string $installPath,
        public AutoloadMap $autoload,
        public ?string $version = null,
        public ?string $reference = null,
    ) {}
}
