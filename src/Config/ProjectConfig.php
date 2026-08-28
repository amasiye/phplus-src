<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Config;

final readonly class ProjectConfig
{
    /**
     * @param list<string> $sourceRoots
     * @param list<string> $stubPaths
     * @param list<string> $excludedPaths
     */
    public function __construct(
        public string $projectRoot,
        public string $configurationPath,
        public array $sourceRoots,
        public string $outputPath,
        public string $cachePath,
        public string $targetPhpVersion,
        public array $stubPaths,
        public array $excludedPaths,
    ) {
        if ($sourceRoots === []) {
            throw new \InvalidArgumentException('A project configuration requires a source root.');
        }
    }
}
