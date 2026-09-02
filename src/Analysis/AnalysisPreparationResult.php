<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class AnalysisPreparationResult
{
    public function __construct(
        public readonly ?AnalysisProject $project,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => $this->project !== null && !$this->diagnostics->hasErrors;
    }
}
