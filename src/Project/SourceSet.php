<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Project;

use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

/** @implements \IteratorAggregate<int, ProjectSource> */
final class SourceSet implements \Countable, \IteratorAggregate
{
    /** @var array<string, ProjectSource> */
    private array $sources = [];

    /** @param iterable<ProjectSource> $sources */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->sources[Path::comparisonKey($source->path)] = $source;
        }

        ksort($this->sources, SORT_STRING);
    }

    public function count(): int
    {
        return count($this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    public function find(string $path): ?ProjectSource
    {
        return $this->sources[Path::comparisonKey($path)] ?? null;
    }

    public function contains(ProjectSource $source): bool
    {
        return isset($this->sources[Path::comparisonKey($source->path)]);
    }

    public function owns(string $path): bool
    {
        return $this->find($path) !== null;
    }

    public function phplusFiles(): self
    {
        return $this->ofKind(FileKind::Phplus);
    }

    public function phpFiles(): self
    {
        return $this->ofKind(FileKind::Php);
    }

    /** @return list<ProjectSource> */
    public function files(): array
    {
        return array_values($this->sources);
    }

    public function beneath(string $directory): self
    {
        return new self(array_filter(
            $this->sources,
            static fn (ProjectSource $source): bool => Path::contains($directory, $source->path),
        ));
    }

    public function ofKind(FileKind $kind): self
    {
        return new self(array_filter(
            $this->sources,
            static fn (ProjectSource $source): bool => $source->kind === $kind,
        ));
    }

    /** @return \Traversable<int, ProjectSource> */
    public function getIterator(): \Traversable
    {
        yield from $this->files();
    }
}
