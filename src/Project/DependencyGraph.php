<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Support\Path;

final class DependencyGraph
{
    /** @var array<string, string> */
    private array $nodes = [];

    /** @var array<string, array<string, true>> */
    private array $dependencies = [];

    public function addNode(string $path): void
    {
        $path = Path::normalize($path);
        $key = Path::comparisonKey($path);
        $this->nodes[$key] = $path;
        $this->dependencies[$key] ??= [];
        ksort($this->nodes, SORT_STRING);
        ksort($this->dependencies, SORT_STRING);
    }

    public function addDependency(string $source, string $dependency): void
    {
        $this->addNode($source);
        $this->addNode($dependency);
        $sourceKey = Path::comparisonKey($source);
        $dependencyKey = Path::comparisonKey($dependency);
        $this->dependencies[$sourceKey][$dependencyKey] = true;
        ksort($this->dependencies[$sourceKey], SORT_STRING);
    }

    /** @return list<string> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<string> */
    public function dependenciesOf(string $source): array
    {
        $dependencies = [];

        foreach (array_keys($this->dependencies[Path::comparisonKey($source)] ?? []) as $key) {
            if (isset($this->nodes[$key])) {
                $dependencies[] = $this->nodes[$key];
            }
        }

        return $dependencies;
    }

    /** @return list<string> */
    public function dependentsOf(string $dependency): array
    {
        $dependencyKey = Path::comparisonKey($dependency);
        $dependents = [];

        foreach ($this->dependencies as $sourceKey => $dependencies) {
            if (isset($dependencies[$dependencyKey], $this->nodes[$sourceKey])) {
                $dependents[] = $this->nodes[$sourceKey];
            }
        }

        return $dependents;
    }
}
