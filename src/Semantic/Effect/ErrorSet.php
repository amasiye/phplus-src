<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

/** @implements \IteratorAggregate<int, ErrorOccurrence> */
final class ErrorSet implements \IteratorAggregate, \Countable
{
    /** @var array<string, ErrorOccurrence> */
    private array $occurrences = [];

    public function add(ErrorOccurrence $occurrence): bool
    {
        $key = strtolower($occurrence->canonicalType);

        if (isset($this->occurrences[$key])) {
            return false;
        }

        $this->occurrences[$key] = $occurrence;

        return true;
    }

    public function combine(self $other): self
    {
        $combined = new self();

        foreach ([...$this->values, ...$other->values] as $occurrence) {
            $combined->add($occurrence);
        }

        return $combined;
    }

    /** @param list<string> $caughtTypes */
    public function excludeCaught(array $caughtTypes, ThrowableHierarchy $hierarchy): self
    {
        $remaining = new self();

        foreach ($this->occurrences as $occurrence) {
            $handled = false;

            foreach ($caughtTypes as $caughtType) {
                if ($hierarchy->matchesSubtype($occurrence->canonicalType, $caughtType)) {
                    $handled = true;
                    break;
                }
            }

            if (!$handled) {
                $remaining->add($occurrence);
            }
        }

        return $remaining;
    }

    /** @return \Traversable<int, ErrorOccurrence> */
    public function getIterator(): \Traversable
    {
        yield from $this->values;
    }

    public function count(): int
    {
        return count($this->occurrences);
    }

    /** @var list<ErrorOccurrence> */
    public array $values {
        get => array_values($this->occurrences);
    }

    /** @var list<string> */
    public array $types {
        get => array_map(static fn (ErrorOccurrence $occurrence): string => $occurrence->canonicalType, $this->values);
    }

    public bool $isEmpty {
        get => $this->occurrences === [];
    }
}
