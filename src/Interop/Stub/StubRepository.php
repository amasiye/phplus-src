<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Stub;

use Amasiye\Phplus\Support\Path;

/** @implements \IteratorAggregate<int, StubFile> */
final class StubRepository implements \Countable, \IteratorAggregate
{
    /** @var array<string, StubFile> */
    private array $filesByPath = [];

    /** @param iterable<StubFile> $files */
    public function __construct(iterable $files = [])
    {
        foreach ($files as $file) {
            $this->filesByPath[Path::buildComparisonKey($file->path)] = $file;
        }

        ksort($this->filesByPath, SORT_STRING);
    }

    public function count(): int
    {
        return count($this->filesByPath);
    }

    /** @var list<StubFile> */
    public array $files {
        get => array_values($this->filesByPath);
    }

    /** @return \Traversable<int, StubFile> */
    public function getIterator(): \Traversable
    {
        yield from $this->files;
    }
}
