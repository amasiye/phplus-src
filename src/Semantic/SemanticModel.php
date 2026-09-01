<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\Binding\BindingTable;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorIndex;
use Amasiye\Ppphp\Semantic\Type\ExpressionTypeTable;
use Amasiye\Ppphp\Semantic\When\WhenExpressionIndex;

final class SemanticModel
{
    public function __construct(
        public readonly ParsedFile $parsedFile,
        public readonly BindingTable $bindings,
        public readonly DiagnosticBag $diagnostics,
        public readonly CallableErrorIndex $errorContracts,
        public readonly WhenExpressionIndex $whenExpressions = new WhenExpressionIndex(),
        public readonly ExpressionTypeTable $expressionTypes = new ExpressionTypeTable(),
    ) {}

    public bool $isSuccessful {
        get => !$this->diagnostics->hasErrors;
    }
}
