<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

final readonly class PhpStanFinding
{
    public function __construct(
        public string $path,
        public string $message,
        public int $line,
        public ?string $identifier,
        public bool $ignorable,
    ) {}
}
