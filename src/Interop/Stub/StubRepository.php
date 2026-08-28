<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Stub;

use Amasiye\Phplus\Support\Path;

/** @implements \IteratorAggregate<int, StubFile> */
final class StubRepository implements \Countable, \IteratorAggregate
{
    /** @var array<string, StubFile> */
    private array $files = [];

    /** @param iterable<StubFile> $files */
    public function __construct(iterable $files = [])
    {
        foreach ($files as $file) {
            $this->files[Path::comparisonKey($file->path)] = $file;
        }

        ksort($this->files, SORT_STRING);
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** @return list<StubFile> */
    public function files(): array
    {
        return array_values($this->files);
    }

    /** @return \Traversable<int, StubFile> */
    public function getIterator(): \Traversable
    {
        yield from $this->files();
    }

}
