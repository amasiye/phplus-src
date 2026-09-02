<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorIndex;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;

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
