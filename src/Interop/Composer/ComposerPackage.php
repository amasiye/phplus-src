<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

final readonly class ComposerPackage
{
    /**
     * @param array<string, string> $requirements
     * @param array<string, string> $extensionRequirements
     */
    public function __construct(
        public string $name,
        public string $installPath,
        public AutoloadMap $autoload,
        public ?string $version = null,
        public ?string $reference = null,
        public ?string $prettyVersion = null,
        public ?string $type = null,
        public bool $developmentOnly = false,
        public array $requirements = [],
        public array $extensionRequirements = [],
        public ?string $installedMetadataIdentity = null,
    ) {}
}
