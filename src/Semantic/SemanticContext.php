<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic;

use Atatusoft\Ppphp\Frontend\ParsedFile;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationIndex;
use Atatusoft\Ppphp\Semantic\Scope\ScopeStack;
use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;

final readonly class SemanticContext
{
    public function __construct(
        public ParsedFile $parsedFile,
        public SemanticModel $model,
        public ScopeStack $scopes,
        public SymbolTable $symbols,
        public ResolvedNameTable $resolvedNames,
        public GenericDeclarationIndex $genericDeclarations,
    ) {}
}
