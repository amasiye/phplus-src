<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Support\Path;

final class DiagnosticDeduplicator
{
    /** @param iterable<Diagnostic> $diagnostics */
    public function deduplicate(iterable $diagnostics): DiagnosticBag
    {
        $result = new DiagnosticBag();
        $exactKeys = [];
        $internalLines = [];

        foreach ($diagnostics as $diagnostic) {
            $span = $diagnostic->primary?->span;
            $path = $span === null ? '' : Path::buildComparisonKey($span->sourceFile->path);
            $line = $span?->start->line ?? 0;
            $lineKey = implode('|', [$diagnostic->code->value, $path, (string) $line]);
            $isBackend = array_key_exists('backendIdentifier', $diagnostic->debug);

            if ($isBackend && isset($internalLines[$lineKey])) {
                continue;
            }

            $exactKey = implode('|', [
                $diagnostic->code->value,
                $path,
                (string) ($span?->start->offset ?? -1),
                (string) ($span?->end->offset ?? -1),
                $diagnostic->message,
            ]);

            if (isset($exactKeys[$exactKey])) {
                continue;
            }

            $exactKeys[$exactKey] = true;

            if (!$isBackend) {
                $internalLines[$lineKey] = true;
            }

            $result->add($diagnostic);
        }

        return $result;
    }
}
