<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;

final class ClassSymbol
{
    /** @var array<string, MethodSymbol> */
    private array $recordedMethods = [];

    /** @var array<string, PropertySymbol> */
    private array $recordedProperties = [];

    private ?GenericDeclarationEntry $genericState = null;

    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    public function __construct(
        public readonly string $fullyQualifiedName,
        public readonly string $namespace,
        public readonly string $kind,
        public readonly SourceFile $sourceFile,
        public readonly Span $declarationSpan,
        public readonly ?string $parent = null,
        public readonly array $interfaces = [],
        public readonly array $traits = [],
    ) {}

    public function declareMethod(MethodSymbol $method): void
    {
        $this->recordedMethods[strtolower($method->name)] = $method;
    }

    public function declareProperty(PropertySymbol $property): void
    {
        $this->recordedProperties[$property->name] = $property;
    }

    public function findMethod(string $name): ?MethodSymbol
    {
        return $this->recordedMethods[strtolower($name)] ?? null;
    }

    public function findProperty(string $name): ?PropertySymbol
    {
        return $this->recordedProperties[$name] ?? null;
    }

    public function attachGenericDeclaration(GenericDeclarationEntry $declaration): void
    {
        $this->genericState = $declaration;
    }

    public ?GenericDeclarationEntry $genericDeclaration {
        get => $this->genericState;
    }

    /** @var list<MethodSymbol> */
    public array $methods {
        get => array_values($this->recordedMethods);
    }

    /** @var list<PropertySymbol> */
    public array $properties {
        get => array_values($this->recordedProperties);
    }
}
