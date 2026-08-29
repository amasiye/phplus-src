<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;

final class FunctionSymbol
{
    private CallableErrorContract $errorState;

    /** @param list<ParameterSymbol> $parameters */
    public function __construct(
        public readonly string $fullyQualifiedName,
        public readonly string $namespace,
        public readonly array $parameters,
        public readonly ?NamedType $returnType,
        public readonly bool $byReference,
        public readonly SourceFile $sourceFile,
        public readonly Span $declarationSpan,
    ) {
        $this->errorState = CallableErrorContract::createEmpty($declarationSpan);
    }

    public CallableErrorContract $errorContract {
        get => $this->errorState;
    }

    public function replaceErrorContract(CallableErrorContract $contract): void
    {
        $this->errorState = $contract;
    }
}
