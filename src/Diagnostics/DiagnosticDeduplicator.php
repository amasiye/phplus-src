<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Support\Path;

final class DiagnosticDeduplicator
{
    /** @param iterable<Diagnostic> $diagnostics */
    public function deduplicate(iterable $diagnostics): DiagnosticBag
    {
        /** @var array<string, Diagnostic> $unique */
        $unique = [];

        foreach ($diagnostics as $diagnostic) {
            $key = $this->buildKey($diagnostic);
            $existing = $unique[$key] ?? null;

            if ($existing === null || $this->originRank($diagnostic) < $this->originRank($existing)) {
                $unique[$key] = $diagnostic;
            }
        }

        return new DiagnosticBag(array_values($unique));
    }

    private function buildKey(Diagnostic $diagnostic): string
    {
        $span = $diagnostic->primary?->span;
        $related = array_map(
            static fn (DiagnosticLabel $label): array => [
                Path::buildComparisonKey($label->span->sourceFile->path),
                $label->span->start->offset,
                $label->span->end->offset,
                $label->message,
            ],
            $diagnostic->related,
        );

        return hash('sha256', serialize([
            $diagnostic->code->value,
            $span === null ? null : Path::buildComparisonKey($span->sourceFile->path),
            $span?->start->offset,
            $span?->end->offset,
            $diagnostic->identity,
            $diagnostic->message,
            $diagnostic->primary?->message,
            $related,
            $diagnostic->help,
        ]));
    }

    private function originRank(Diagnostic $diagnostic): int
    {
        return match ($diagnostic->origin) {
            Enumerations\DiagnosticOrigin::Compiler => 0,
            Enumerations\DiagnosticOrigin::PhpParser => 1,
            Enumerations\DiagnosticOrigin::PhpStan => 2,
            Enumerations\DiagnosticOrigin::Subprocess => 3,
        };
    }
}
