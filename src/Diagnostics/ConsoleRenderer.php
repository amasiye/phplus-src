<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Interfaces\DiagnosticRenderer;

final class ConsoleRenderer implements DiagnosticRenderer
{
    public function render(DiagnosticBag $diagnostics, bool $includeDebug = false): string
    {
        $rendered = [];

        foreach ($diagnostics as $diagnostic) {
            $rendered[] = $this->renderDiagnostic($diagnostic, $includeDebug);
        }

        return $rendered === [] ? '' : implode("\n\n", $rendered) . "\n";
    }

    private function renderDiagnostic(Diagnostic $diagnostic, bool $includeDebug): string
    {
        $lines = [
            sprintf(
                '%s[%s]: %s',
                ucfirst($diagnostic->severity->value),
                $diagnostic->code->value,
                $diagnostic->title,
            ),
            '',
            $diagnostic->message,
        ];

        if ($diagnostic->primary !== null) {
            $lines[] = '';
            array_push($lines, ...$this->renderLabel($diagnostic->primary));
        }

        foreach ($diagnostic->related as $related) {
            $lines[] = '';
            $lines[] = 'Related: ' . $related->message;
            array_push($lines, ...$this->renderLabel($related, false));
        }

        if ($diagnostic->help !== null) {
            $lines[] = '';
            $lines[] = 'Help: ' . $diagnostic->help;
        }

        if ($includeDebug && $diagnostic->debug !== []) {
            $lines[] = '';
            $lines[] = 'Debug:';

            foreach ($diagnostic->debug as $key => $value) {
                $value = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $debugLines = explode("\n", $value);
                $lines[] = sprintf('  %s: %s', $key, array_shift($debugLines));

                foreach ($debugLines as $debugLine) {
                    $lines[] = '    ' . $debugLine;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function renderLabel(DiagnosticLabel $label, bool $includeMessage = true): array
    {
        $span = $label->span;
        $start = $span->start;
        $end = $span->end;
        $source = $span->sourceFile;
        $lineNumber = (string) $start->line;
        $gutterWidth = strlen($lineNumber);
        $sourceLine = $source->readLineText($start->line);
        $underlineLength = $end->line === $start->line
            ? max(1, $end->column - $start->column)
            : max(1, $this->countCodePoints($sourceLine) - $start->column + 2);
        $underline = str_repeat(' ', $start->column - 1)
            . str_repeat('^', $underlineLength);

        if ($includeMessage && $label->message !== '') {
            $underline .= ' ' . $label->message;
        }

        $lines = [
            sprintf('  %s:%d:%d', $source->displayPath, $start->line, $start->column),
            '',
            sprintf('%' . $gutterWidth . 's | %s', $lineNumber, $sourceLine),
            str_repeat(' ', $gutterWidth) . ' | ' . $underline,
        ];

        if ($end->line > $start->line) {
            $lines[] = str_repeat(' ', $gutterWidth) . sprintf(' | ... through line %d', $end->line);
        }

        return $lines;
    }

    private function countCodePoints(string $value): int
    {
        $count = 0;

        for ($offset = 0, $length = strlen($value); $offset < $length; $offset++) {
            if ((ord($value[$offset]) & 0xC0) !== 0x80) {
                $count++;
            }
        }

        return $count;
    }
}
