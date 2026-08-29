<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;

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

    public bool $isEmpty {
        get => $this->diagnostics === [];
    }

    public bool $hasErrors {
        get => $this->errors !== [];
    }

    /** @var list<Diagnostic> */
    public array $errors {
        get => $this->filterBySeverity(Severity::Error);
    }

    /** @var list<Diagnostic> */
    public array $warnings {
        get => $this->filterBySeverity(Severity::Warning);
    }

    /** @var list<Diagnostic> */
    public array $notes {
        get => $this->filterBySeverity(Severity::Note);
    }

    /** @return \Traversable<int, Diagnostic> */
    public function getIterator(): \Traversable
    {
        yield from $this->diagnostics;
    }

    /** @return list<Diagnostic> */
    private function filterBySeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity === $severity,
        ));
    }
}
