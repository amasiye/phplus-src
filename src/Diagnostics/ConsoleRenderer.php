<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;

final readonly class ConsoleRenderer
{
    public function __construct(
        private DiagnosticProcessor $processor = new DiagnosticProcessor(),
        private DiagnosticDebugNormalizer $debugNormalizer = new DiagnosticDebugNormalizer(),
    ) {}

    public function render(DiagnosticBag $diagnostics, bool|ConsoleRenderOptions $options = false): string
    {
        $options = is_bool($options) ? new ConsoleRenderOptions(includeDebug: $options) : $options;
        $rendered = [];

        foreach ($this->processor->process($diagnostics) as $diagnostic) {
            $rendered[] = $this->renderDiagnostic($diagnostic, $options);
        }

        return $rendered === [] ? '' : implode("\n\n", $rendered) . "\n";
    }

    private function renderDiagnostic(Diagnostic $diagnostic, ConsoleRenderOptions $options): string
    {
        $heading = sprintf('%s[%s]: %s', ucfirst($diagnostic->severity->value), $diagnostic->code->value, $diagnostic->title);
        $lines = [
            $this->style($this->sanitize($heading), $this->severityStyle($diagnostic->severity), $options),
            '',
            ...explode("\n", $this->sanitize($diagnostic->message)),
        ];

        if ($diagnostic->primary !== null) {
            $lines[] = '';
            array_push($lines, ...$this->renderLabel($diagnostic->primary, $diagnostic->severity, $options));
        }

        foreach ($diagnostic->related as $related) {
            $lines[] = '';
            $lines[] = $this->style('Related:', '36;1', $options) . ' ' . $this->sanitize($related->message);
            array_push($lines, ...$this->renderLabel($related, Severity::Note, $options, false));
        }

        if ($diagnostic->help !== null) {
            $lines[] = '';
            $help = explode("\n", $this->sanitize($diagnostic->help));
            $lines[] = $this->style('Help:', '32;1', $options) . ' ' . array_shift($help);

            foreach ($help as $helpLine) {
                $lines[] = '      ' . $helpLine;
            }
        }

        if ($options->includeDebug) {
            $debug = $this->debugNormalizer->normalize([
                'origin' => $diagnostic->origin->value,
                ...$diagnostic->debug,
            ]);
            $lines[] = '';
            $lines[] = $this->style('Debug:', '2', $options);

            foreach ($debug as $key => $value) {
                $encoded = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $debugLines = explode("\n", $this->sanitize($encoded));
                $lines[] = sprintf('  %s: %s', $key, array_shift($debugLines));

                foreach ($debugLines as $debugLine) {
                    $lines[] = '    ' . $debugLine;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function renderLabel(
        DiagnosticLabel $label,
        Severity $severity,
        ConsoleRenderOptions $options,
        bool $includeMessage = true,
    ): array {
        $span = $label->span;
        $source = $span->sourceFile;
        $startLine = max(1, $span->start->line - $options->contextLineCount);
        $highlightEnd = $this->resolveHighlightEndLine($label);
        $endLine = min($source->lineCount, $highlightEnd + $options->contextLineCount);
        $visibleHighlightLines = $this->selectHighlightLines($span->start->line, $highlightEnd);
        $visibleLines = [];

        for ($line = $startLine; $line <= $endLine; $line++) {
            if ($line < $span->start->line || $line > $highlightEnd || in_array($line, $visibleHighlightLines, true)) {
                $visibleLines[] = $line;
            }
        }

        $gutterWidth = strlen((string) max($visibleLines === [] ? [$span->start->line] : $visibleLines));
        $lines = [sprintf(
            '  %s %s:%d:%d',
            $this->style('-->', '36;1', $options),
            $this->sanitize(str_replace('\\', '/', $source->displayPath)),
            $span->start->line,
            $span->start->column,
        )];
        $previous = null;

        foreach ($visibleLines as $line) {
            if ($previous !== null && $line > $previous + 1) {
                $lines[] = str_repeat(' ', $gutterWidth + 1) . $this->style('...', '2', $options);
            }

            $sourceLine = $source->readLineText($line);
            $expanded = $this->expandTabs($this->sanitize($sourceLine));
            $available = max(1, $options->terminalWidth - $gutterWidth - 3);
            [$column, $length] = $line >= $span->start->line && $line <= $highlightEnd
                ? $this->resolveUnderline($label, $line)
                : [1, 1];
            [$expanded, $column, $length] = $this->clip($expanded, $available, $column, $length);
            $lineNumber = str_pad((string) $line, $gutterWidth, ' ', STR_PAD_LEFT);
            $lines[] = sprintf('%s %s %s', $this->style($lineNumber, '34', $options), $this->style('|', '34', $options), $expanded);

            if ($line >= $span->start->line && $line <= $highlightEnd) {
                $indent = min($available - 1, $column - 1);
                $underline = str_repeat(' ', $indent)
                    . str_repeat('^', max(1, min($length, $available - $indent)));

                if ($includeMessage && $line === $span->start->line && $label->message !== '') {
                    $underline .= ' ' . $this->sanitize($label->message);
                }

                $lines[] = str_repeat(' ', $gutterWidth + 1)
                    . $this->style('|', '34', $options)
                    . ' '
                    . $this->style($underline, $this->severityStyle($severity), $options);
            }

            $previous = $line;
        }

        return $lines;
    }

    /** @return list<int> */
    private function selectHighlightLines(int $start, int $end): array
    {
        if ($end - $start < 4) {
            return range($start, $end);
        }

        return [$start, $start + 1, $end - 1, $end];
    }

    private function resolveHighlightEndLine(DiagnosticLabel $label): int
    {
        $span = $label->span;

        if (
            !$span->isEmpty
            && $span->end->line > $span->start->line
            && $span->end->offset === $span->sourceFile->resolveLineStartOffset($span->end->line)
        ) {
            return $span->end->line - 1;
        }

        return max($span->start->line, $span->end->line);
    }

    /** @return array{int, int} */
    private function resolveUnderline(DiagnosticLabel $label, int $line): array
    {
        $span = $label->span;
        $sourceLine = $span->sourceFile->readLineText($line);
        $start = $line === $span->start->line ? $span->start->column : 1;
        $end = $line === $span->end->line
            ? $span->end->column
            : $this->countCodePoints($sourceLine) + 1;
        $visualStart = $this->resolveVisualColumn($sourceLine, $start);
        $visualEnd = $this->resolveVisualColumn($sourceLine, max($start, $end));

        return [$visualStart, max(1, $visualEnd - $visualStart)];
    }

    private function resolveVisualColumn(string $line, int $column): int
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line);
        $visual = 1;

        foreach (array_slice($characters, 0, max(0, $column - 1)) as $character) {
            if ($character === "\t") {
                $visual += 4 - (($visual - 1) % 4);
                continue;
            }

            $visual += preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $character) === 1 ? 4 : 1;
        }

        return $visual;
    }

    private function expandTabs(string $line): string
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line);
        $expanded = '';
        $column = 1;

        foreach ($characters as $character) {
            if ($character === "\t") {
                $width = 4 - (($column - 1) % 4);
                $expanded .= str_repeat(' ', $width);
                $column += $width;
                continue;
            }

            $expanded .= $character;
            $column++;
        }

        return $expanded;
    }

    /** @return array{string, int, int} */
    private function clip(string $line, int $width, int $highlightColumn, int $highlightLength): array
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line);

        if (count($characters) <= $width) {
            return [$line, $highlightColumn, $highlightLength];
        }

        $highlightIndex = max(0, $highlightColumn - 1);
        $context = min(8, max(2, intdiv($width, 4)));
        $start = max(0, $highlightIndex - $context);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $hasLeft = $start > 0;
            $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - 1);
            $end = min(count($characters), $start + $contentWidth);
            $hasRight = $end < count($characters);
            $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - ($hasRight ? 1 : 0));
            $end = min(count($characters), $start + $contentWidth);

            if ($highlightIndex < $end) {
                break;
            }

            $start = max(0, $highlightIndex - $contentWidth + 1);
        }

        $hasLeft = $start > 0;
        $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - 1);
        $end = min(count($characters), $start + $contentWidth);
        $hasRight = $end < count($characters);
        $contentWidth = max(1, $width - ($hasLeft ? 1 : 0) - ($hasRight ? 1 : 0));
        $end = min(count($characters), $start + $contentWidth);
        $visible = implode('', array_slice($characters, $start, $end - $start));
        $clipped = ($hasLeft ? '…' : '') . $visible . ($hasRight ? '…' : '');
        $column = $highlightIndex - $start + 1 + ($hasLeft ? 1 : 0);
        $length = max(1, min($highlightLength, $end - max($start, $highlightIndex)));

        return [$clipped, $column, $length];
    }

    private function sanitize(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            str_replace("\r\n", "\n", str_replace("\r", "\n", $value)),
        ) ?? $value;
    }

    private function style(string $value, string $style, ConsoleRenderOptions $options): string
    {
        return $options->decorated ? "\033[{$style}m{$value}\033[0m" : $value;
    }

    private function severityStyle(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => '31;1',
            Severity::Warning => '33;1',
            Severity::Note => '36;1',
        };
    }

    private function countCodePoints(string $value): int
    {
        return count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value));
    }
}
