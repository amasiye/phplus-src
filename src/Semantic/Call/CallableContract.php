<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Effect\CallableErrorContract;
use Atatusoft\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Source\Span;

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
