<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Composer;

use Amasiye\Phplus\Support\Path;

final class AutoloadMap
{
    /**
     * @param array<string, list<string>> $psr4
     * @param list<string> $classmap
     * @param list<string> $files
     */
    public function __construct(
        public readonly array $psr4 = [],
        public readonly array $classmap = [],
        public readonly array $files = [],
    ) {}

    /** @var list<string> */
    public array $paths {
        get {
            $paths = [...$this->classmap, ...$this->files];

            foreach ($this->psr4 as $directories) {
                array_push($paths, ...$directories);
            }

            $paths = array_values(array_unique(array_map(Path::normalize(...), $paths)));
            usort($paths, static fn (string $left, string $right): int =>
                Path::buildComparisonKey($left) <=> Path::buildComparisonKey($right));

            return $paths;
        }
    }
}
