<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

/** @implements \IteratorAggregate<int, OutputPlanEntry> */
final readonly class OutputPlan implements \Countable, \IteratorAggregate
{
    /** @param list<OutputPlanEntry> $entries */
    public function __construct(public array $entries) {}

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \Traversable<int, OutputPlanEntry> */
    public function getIterator(): \Traversable
    {
        yield from $this->entries;
    }
}
