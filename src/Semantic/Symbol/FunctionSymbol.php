<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;
use Amasiye\Ppphp\Source\Enumerations\FileKind;

final class FunctionSymbol
{
    private CallableErrorContract $errorState;

    private ?GenericDeclarationEntry $genericState = null;

    /** @param list<ParameterSymbol> $parameters */
    public function __construct(
        public readonly string $fullyQualifiedName,
        public readonly string $namespace,
        public readonly array $parameters,
        public readonly ?NamedType $returnType,
        public readonly bool $byReference,
        public readonly SourceFile $sourceFile,
        public readonly Span $declarationSpan,
        public readonly Span $selectionSpan,
        public readonly ?Type $documentedReturnType = null,
        public readonly bool $hasBody = true,
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

            if ($this->documentedReturnType !== null
                && ($this->sourceFile->kind !== FileKind::Ppphp || $native === null || $native->canonical === 'mixed')) {
                return $this->documentedReturnType;
            }

            return $native;
        }
    }
}
