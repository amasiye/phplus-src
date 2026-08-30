<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorIndex;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;

final readonly class ProjectSemanticContext
{
    /**
     * @param array<string, true> $diagnosticSourceFiles
     */
    public function __construct(
        public ProjectParseResult $parseResult,
        public SymbolTable $symbols,
        public ResolvedNameTable $resolvedNames,
        public DiagnosticBag $diagnostics,
        public CallableErrorIndex $errorContracts,
        public array $diagnosticSourceFiles,
    ) {}
}
