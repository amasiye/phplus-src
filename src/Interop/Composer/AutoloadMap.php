<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

use Amasiye\Ppphp\Support\Path;

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

            return $this->stableUnique($paths);
        }
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function stableUnique(array $paths): array
    {
        $result = [];
        $seen = [];

        foreach ($paths as $path) {
            $normalized = Path::normalize($path);
            $key = Path::buildComparisonKey($normalized);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $normalized;
            }
        }

        return $result;
    }
}
