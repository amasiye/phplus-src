<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

final readonly class ConsoleRenderOptions
{
    public function __construct(
        public bool $includeDebug = false,
        public bool $decorated = false,
        public int $terminalWidth = 120,
        public int $contextLineCount = 1,
    ) {
        if ($terminalWidth < 20) {
            throw new \InvalidArgumentException('The diagnostic terminal width must be at least 20 columns.');
        }

        if ($contextLineCount < 0) {
            throw new \InvalidArgumentException('The diagnostic context line count cannot be negative.');
        }
    }
}
