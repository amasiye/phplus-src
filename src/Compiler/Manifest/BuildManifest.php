<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Manifest;

use Amasiye\Ppphp\Support\Path;

final readonly class BuildManifest
{
    public const int FORMAT_VERSION = 2;

    /** @param list<BuildManifestEntry> $files */
    public function __construct(
        public string $compilerName,
        public string $compilerVersion,
        public string $compilerBuildIdentity,
        public int $loweringFormatVersion,
        public string $targetPhpVersion,
        public string $configurationFingerprint,
        public bool $completeProject,
        public array $files,
    ) {}

    public function findBySource(string $source): ?BuildManifestEntry
    {
        $key = strtolower(Path::normalize($source));

        foreach ($this->files as $entry) {
            if (strtolower(Path::normalize($entry->source)) === $key) {
                return $entry;
            }
        }

        return null;
    }
}
