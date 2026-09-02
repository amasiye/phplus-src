<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\PhpStan;

final readonly class PhpStanAnalysisPlan
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public string $workingDirectory,
        public string $configurationPath,
        public string $resultPath,
    ) {}
}
