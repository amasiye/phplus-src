<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

final class AnalysisResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly DiagnosticBag $diagnostics,
        public readonly array $metadata = [],
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
