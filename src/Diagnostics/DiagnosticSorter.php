<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;
use Atatusoft\Ppphp\Support\Path;

final class DiagnosticSorter
{
    /** @param iterable<Diagnostic> $diagnostics */
    public function sort(iterable $diagnostics): DiagnosticBag
    {
        $indexed = [];

        foreach ($diagnostics as $index => $diagnostic) {
            $indexed[] = ['index' => $index, 'diagnostic' => $diagnostic];
        }

        usort($indexed, function (array $left, array $right): int {
            $comparison = $this->buildKey($left['diagnostic']) <=> $this->buildKey($right['diagnostic']);

            return $comparison !== 0 ? $comparison : $left['index'] <=> $right['index'];
        });

        return new DiagnosticBag(array_map(
            static fn (array $item): Diagnostic => $item['diagnostic'],
            $indexed,
        ));
    }

    /** @return array{int, int, string, int, int, string, string, string} */
    private function buildKey(Diagnostic $diagnostic): array
    {
        $span = $diagnostic->primary?->span;

        return [
            match ($diagnostic->severity) {
                Severity::Error => 0,
                Severity::Warning => 1,
                Severity::Note => 2,
            },
            $span === null ? 1 : 0,
            $span === null ? '' : Path::buildComparisonKey($span->sourceFile->displayPath),
            $span?->start->offset ?? PHP_INT_MAX,
            $span?->end->offset ?? PHP_INT_MAX,
            $diagnostic->code->value,
            $diagnostic->identity ?? '',
            $diagnostic->message,
        ];
    }
}
