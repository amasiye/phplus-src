<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Support\Path;

final class DependencyGraph
{
    /** @var array<string, string> */
    private array $nodesByPath = [];

    /** @var array<string, array<string, true>> */
    private array $dependencies = [];

    public function addNode(string $path): void
    {
        $path = Path::normalize($path);
        $key = Path::buildComparisonKey($path);
        $this->nodesByPath[$key] = $path;
        $this->dependencies[$key] ??= [];
        ksort($this->nodesByPath, SORT_STRING);
        ksort($this->dependencies, SORT_STRING);
    }

    public function addDependency(string $source, string $dependency): void
    {
        $this->addNode($source);
        $this->addNode($dependency);
        $sourceKey = Path::buildComparisonKey($source);
        $dependencyKey = Path::buildComparisonKey($dependency);
        $this->dependencies[$sourceKey][$dependencyKey] = true;
        ksort($this->dependencies[$sourceKey], SORT_STRING);
    }

    /** @var list<string> */
    public array $nodes {
        get => array_values($this->nodesByPath);
    }

    /** @return list<string> */
    public function findDependenciesOf(string $source): array
    {
        $dependencies = [];

        foreach (array_keys($this->dependencies[Path::buildComparisonKey($source)] ?? []) as $key) {
            if (isset($this->nodesByPath[$key])) {
                $dependencies[] = $this->nodesByPath[$key];
            }
        }

        return $dependencies;
    }

    /** @return list<string> */
    public function findDependentsOf(string $dependency): array
    {
        $dependencyKey = Path::buildComparisonKey($dependency);
        $dependents = [];

        foreach ($this->dependencies as $sourceKey => $dependencies) {
            if (isset($dependencies[$dependencyKey], $this->nodesByPath[$sourceKey])) {
                $dependents[] = $this->nodesByPath[$sourceKey];
            }
        }

        return $dependents;
    }
}
