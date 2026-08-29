<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Support\Path;

final class SemanticAnalysisResult
{
    /** @param array<string, SemanticModel> $models */
    public function __construct(
        public readonly array $models,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }

    public function findModel(string $path): ?SemanticModel
    {
        return $this->models[Path::buildComparisonKey($path)] ?? null;
    }
}
