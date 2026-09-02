<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

final readonly class ComposerProject
{
    /** @param list<ComposerPackage> $dependencies */
    public function __construct(
        public string $projectRoot,
        public ?string $configurationPath,
        public string $vendorPath,
        public AutoloadMap $projectAutoload,
        public AutoloadMap $dependencyAutoload,
        public array $dependencies = [],
        public ?string $composerLockIdentity = null,
        public ?string $installedMetadataIdentity = null,
    ) {}

}
