<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

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
