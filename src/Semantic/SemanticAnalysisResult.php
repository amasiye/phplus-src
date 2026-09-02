<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;

final class SemanticAnalysisResult
{
    /** @param array<string, SemanticModel> $models */
    public function __construct(
        public readonly array $models,
        public readonly DiagnosticBag $diagnostics,
        public readonly SymbolTable $symbols = new SymbolTable(),
        public readonly ResolvedNameTable $resolvedNames = new ResolvedNameTable(),
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }

    public function findModel(string $path): ?SemanticModel
    {
        return $this->models[Path::buildComparisonKey($path)] ?? null;
    }
}
