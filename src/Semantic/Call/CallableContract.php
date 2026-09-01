<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

use Amasiye\Ppphp\Semantic\Effect\CallableErrorContract;
use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Semantic\Symbol\FunctionSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Source\Span;

final readonly class CallableContract
{
    /**
     * @param list<ParameterSymbol> $parameters
     * @param array<string, Type> $receiverSubstitutions
     */
    public function __construct(
        public CallableKind $kind,
        public string $identity,
        public ?string $owner,
        public CallableOrigin $origin,
        public array $parameters,
        public ?Type $returnType,
        public ?GenericDeclarationEntry $genericDeclaration,
        public array $receiverSubstitutions,
        public CallableErrorContract $errorContract,
        public string $visibility,
        public bool $static,
        public bool $hasBody,
        public ?Span $declarationSpan,
        public ?Span $selectionSpan,
        public FunctionSymbol|MethodSymbol|null $sourceSymbol = null,
    ) {}
}
