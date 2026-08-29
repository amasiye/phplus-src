<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;

final readonly class FunctionSymbol
{
    /** @param list<ParameterSymbol> $parameters */
    public function __construct(
        public string $fullyQualifiedName,
        public string $namespace,
        public array $parameters,
        public ?NamedType $returnType,
        public bool $byReference,
        public SourceFile $sourceFile,
        public Span $declarationSpan,
    ) {}
}
