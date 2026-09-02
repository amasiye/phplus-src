<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

/** @implements \IteratorAggregate<int, ProjectSource> */
final class SourceSet implements \Countable, \IteratorAggregate
{
    /** @var array<string, ProjectSource> */
    private array $sources = [];

    /** @param iterable<ProjectSource> $sources */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->sources[Path::buildComparisonKey($source->path)] = $source;
        }

        ksort($this->sources, SORT_STRING);
    }

    public function count(): int
    {
        return count($this->sources);
    }

    public bool $isEmpty {
        get => $this->sources === [];
    }

    public function find(string $path): ?ProjectSource
    {
        return $this->sources[Path::buildComparisonKey($path)] ?? null;
    }

    public function contains(ProjectSource $source): bool
    {
        return isset($this->sources[Path::buildComparisonKey($source->path)]);
    }

    public function owns(string $path): bool
    {
        return $this->find($path) !== null;
    }

    public SourceSet $ppphpFiles {
        get => $this->filterByKind(FileKind::Ppphp);
    }

    public SourceSet $phpFiles {
        get => $this->filterByKind(FileKind::Php);
    }

    /** @var list<ProjectSource> */
    public array $files {
        get => array_values($this->sources);
    }

    public function filterBeneath(string $directory): self
    {
        return new self(array_filter(
            $this->sources,
            static fn (ProjectSource $source): bool => Path::contains($directory, $source->path),
        ));
    }

    public function filterByKind(FileKind $kind): self
    {
        return new self(array_filter(
            $this->sources,
            static fn (ProjectSource $source): bool => $source->kind === $kind,
        ));
    }

    /** @return \Traversable<int, ProjectSource> */
    public function getIterator(): \Traversable
    {
        yield from $this->files;
    }
}
