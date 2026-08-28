<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\Diagnostic;
use Amasiye\Phplus\Diagnostics\DiagnosticLabel;
use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;
use Amasiye\Phplus\Source\SourceFile;
use Amasiye\Phplus\Source\Span;
use PhpParser\Error;

final class PhpParserDiagnosticMapper
{
    public function map(Error $error, SourceFile $sourceFile): Diagnostic
    {
        $attributes = $error->getAttributes();
        $span = $this->span($attributes, $error->getStartLine(), $sourceFile);

        return new Diagnostic(
            DiagnosticCode::InvalidPhpSyntax,
            Severity::Error,
            'Invalid PHP Syntax',
            sprintf('The source could not be parsed as PHP 8.4. Parser reported: %s', $error->getRawMessage()),
            new DiagnosticLabel($span, 'Syntax error reported here.'),
            debug: [
                'parserError' => $error::class,
                'parserAttributes' => $attributes,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function span(array $attributes, int $reportedLine, SourceFile $sourceFile): Span
    {
        $startAttribute = $attributes['startFilePos'] ?? null;
        $endAttribute = $attributes['endFilePos'] ?? null;

        if (is_int($startAttribute)) {
            $start = max(0, min($sourceFile->length(), $startAttribute));
            $end = $start;

            if (is_int($endAttribute) && $start < $sourceFile->length()) {
                $end = max($start, min($sourceFile->length(), $endAttribute + 1));
            }

            return $sourceFile->span($start, $end);
        }

        $line = max(1, min($sourceFile->lineCount(), $reportedLine));
        $offset = $sourceFile->lineStartOffset($line);

        return $sourceFile->span($offset, $offset);
    }
}
