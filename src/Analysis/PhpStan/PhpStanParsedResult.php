<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

final readonly class PhpStanParsedResult
{
    /**
     * @param list<PhpStanFinding> $findings
     * @param list<string> $globalErrors
     */
    public function __construct(
        public array $findings,
        public array $globalErrors,
    ) {}
}
