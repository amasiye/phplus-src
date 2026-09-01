<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Span;

final class MethodSymbol
{
    private CallableErrorContract $errorState;

    private ?GenericDeclarationEntry $genericState = null;

    /** @param list<ParameterSymbol> $parameters */
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly array $parameters,
        public readonly ?NamedType $returnType,
        public readonly string $visibility,
        public readonly bool $static,
        public readonly bool $byReference,
        public readonly Span $declarationSpan,
        public readonly Span $selectionSpan,
        public readonly ?Type $documentedReturnType = null,
        public readonly bool $hasBody = true,
        public readonly bool $abstract = false,
        public readonly bool $final = false,
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

    public function attachGenericDeclaration(GenericDeclarationEntry $declaration): void
    {
        $this->genericState = $declaration;
    }

    public ?GenericDeclarationEntry $genericDeclaration {
        get => $this->genericState;
    }

    public ?Type $effectiveReturnType {
        get {
            $native = $this->returnType?->semanticType;

            if ($this->documentedReturnType !== null && ($native === null || $native->canonical === 'mixed')) {
                return $this->documentedReturnType;
            }

            return $native;
        }
    }
}
