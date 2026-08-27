<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Source;

use Amasiye\Phplus\Source\Enumerations\FileKind;
use Amasiye\Phplus\Support\Path;

final readonly class SourceFile
{
    /** @var list<int> */
    private array $lineStarts;

    public function __construct(
        string $path,
        public string $displayPath,
        public FileKind $kind,
        public string $contents,
    ) {
        if (!Path::isAbsolute($path)) {
            throw new \InvalidArgumentException('A source file path must be absolute.');
        }

        $this->path = Path::normalize($path);
        $this->lineStarts = $this->calculateLineStarts($contents);
    }

    public string $path;

    public function length(): int
    {
        return strlen($this->contents);
    }

    public function lineCount(): int
    {
        return count($this->lineStarts);
    }

    public function positionAt(int $offset): Position
    {
        if ($offset < 0 || $offset > $this->length()) {
            throw new \OutOfBoundsException('The position offset is outside the source file.');
        }

        $lineIndex = $this->lineIndexAt($offset);
        $lineStart = $this->lineStarts[$lineIndex];
        $column = $this->countCodePoints($lineStart, $offset) + 1;

        return new Position($this, $offset, $lineIndex + 1, $column);
    }

    public function span(int $startOffset, int $endOffset): Span
    {
        return new Span(
            $this->positionAt($startOffset),
            $this->positionAt($endOffset),
        );
    }

    public function lineText(int $line): string
    {
        if ($line < 1 || $line > $this->lineCount()) {
            throw new \OutOfBoundsException('The line is outside the source file.');
        }

        $start = $this->lineStarts[$line - 1];
        $end = $this->lineStarts[$line] ?? $this->length();
        $text = substr($this->contents, $start, $end - $start);

        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        if (str_ends_with($text, "\r")) {
            $text = substr($text, 0, -1);
        }

        return $text;
    }

    /** @return list<int> */
    private function calculateLineStarts(string $contents): array
    {
        $starts = [0];
        $length = strlen($contents);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($contents[$offset] === "\r") {
                if ($offset + 1 < $length && $contents[$offset + 1] === "\n") {
                    $offset++;
                }

                $starts[] = $offset + 1;
                continue;
            }

            if ($contents[$offset] === "\n") {
                $starts[] = $offset + 1;
            }
        }

        return $starts;
    }

    private function lineIndexAt(int $offset): int
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return max(0, $high);
    }

    private function countCodePoints(int $start, int $end): int
    {
        $count = 0;

        for ($offset = $start; $offset < $end; $offset++) {
            if ((ord($this->contents[$offset]) & 0xC0) !== 0x80) {
                $count++;
            }
        }

        return $count;
    }
}
