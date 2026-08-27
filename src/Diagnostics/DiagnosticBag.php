<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics;

use Amasiye\Phplus\Diagnostics\Enumerations\Severity;

/** @implements \IteratorAggregate<int, Diagnostic> */
final class DiagnosticBag implements \Countable, \IteratorAggregate
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /** @param iterable<Diagnostic> $diagnostics */
    public function addAll(iterable $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->add($diagnostic);
        }
    }

    public function count(): int
    {
        return count($this->diagnostics);
    }

    public function isEmpty(): bool
    {
        return $this->diagnostics === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /** @return list<Diagnostic> */
    public function errors(): array
    {
        return $this->withSeverity(Severity::Error);
    }

    /** @return list<Diagnostic> */
    public function warnings(): array
    {
        return $this->withSeverity(Severity::Warning);
    }

    /** @return list<Diagnostic> */
    public function notes(): array
    {
        return $this->withSeverity(Severity::Note);
    }

    /** @return \Traversable<int, Diagnostic> */
    public function getIterator(): \Traversable
    {
        yield from $this->diagnostics;
    }

    /** @return list<Diagnostic> */
    private function withSeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity === $severity,
        ));
    }
}
