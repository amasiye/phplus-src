<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Amasiye\Ppphp\Frontend\Normalization\SourceMap;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;
use PhpParser\Error;

final class PhpParserDiagnosticMapper
{
    public function map(Error $error, SourceFile $sourceFile, ?SourceMap $sourceMap = null): Diagnostic
    {
        $attributes = $error->getAttributes();
        $span = $this->resolveSpan($attributes, $error->getStartLine(), $sourceFile, $sourceMap);

        return new Diagnostic(
            DiagnosticCode::InvalidPhpSyntax,
            sprintf('The source contains invalid PHP 8.4 syntax: %s', $error->getRawMessage()),
            new DiagnosticLabel($span, 'Syntax error reported here.'),
            debug: [
                'parserError' => $error::class,
                'parserAttributes' => $attributes,
            ],
            origin: DiagnosticOrigin::PhpParser,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function resolveSpan(
        array $attributes,
        int $reportedLine,
        SourceFile $sourceFile,
        ?SourceMap $sourceMap,
    ): Span
    {
        $startAttribute = $attributes['startFilePos'] ?? null;
        $endAttribute = $attributes['endFilePos'] ?? null;

        if (is_int($startAttribute)) {
            $normalizedStart = max(0, min($sourceFile->length, $startAttribute));
            $owningSpan = $sourceMap?->resolveOwningSpan($normalizedStart);

            if ($owningSpan !== null) {
                return $owningSpan;
            }

            $start = $sourceMap?->resolveOriginalOffset($normalizedStart) ?? $normalizedStart;
            $end = $start;

            if (is_int($endAttribute) && $start < $sourceFile->length) {
                $normalizedEnd = max(0, min($sourceFile->length, $endAttribute + 1));
                $end = max($start, $sourceMap?->resolveOriginalOffset($normalizedEnd) ?? $normalizedEnd);
            }

            return $sourceFile->createSpan($start, $end);
        }

        $line = max(1, min($sourceFile->lineCount, $reportedLine));
        $offset = $sourceFile->resolveLineStartOffset($line);

        return $sourceFile->createSpan($offset, $offset);
    }
}
