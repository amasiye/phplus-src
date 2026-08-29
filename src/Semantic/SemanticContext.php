<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\Scope\ScopeStack;

final readonly class SemanticContext
{
    public function __construct(
        public ParsedFile $parsedFile,
        public SemanticModel $model,
        public ScopeStack $scopes,
        public CallableSignatureIndex $callableSignatures,
    ) {}
}
