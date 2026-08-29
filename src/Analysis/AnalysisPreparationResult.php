<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\ProjectParseResult;

final class AnalysisPreparationResult
{
    public function __construct(
        public readonly ?AnalysisProject $project,
        public readonly ProjectParseResult $contextParseResult,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => $this->project !== null && !$this->diagnostics->hasErrors;
    }
}
