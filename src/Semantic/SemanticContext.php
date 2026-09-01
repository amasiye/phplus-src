<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationIndex;
use Amasiye\Ppphp\Semantic\Scope\ScopeStack;
use Amasiye\Ppphp\Semantic\Symbol\SymbolTable;

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
