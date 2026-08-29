<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\Binding\BindingTable;

final class SemanticModel
{
    public function __construct(
        public readonly ParsedFile $parsedFile,
        public readonly BindingTable $bindings,
        public readonly DiagnosticBag $diagnostics,
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
