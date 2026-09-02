<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Generic;

use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Source\Span;

final class GenericDeclarationEntry
{
    /** @param list<TypeParameter> $parameters */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $declarationKind,
        public readonly string $namespace,
        public readonly SourceFile $sourceFile,
        public readonly Span $genericSpan,
        public readonly Span $ownerSpan,
        public readonly array $parameters,
        public readonly ClassSymbol|FunctionSymbol|MethodSymbol $owner,
    ) {}

    public bool $isTypeDeclaration {
        get => in_array($this->declarationKind, ['class', 'interface', 'trait'], true);
    }

    public function findParameter(string $name): ?TypeParameter
    {
        foreach ($this->parameters as $parameter) {
            if (strcasecmp($parameter->name, $name) === 0) {
                return $parameter;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function renderTemplateTags(): array
    {
        return array_map(
            static fn (TypeParameter $parameter): string => '@template ' . $parameter->name
                . ($parameter->bound === null ? '' : ' of ' . $parameter->bound->renderPhpDoc()),
            $this->parameters,
        );
    }
}
