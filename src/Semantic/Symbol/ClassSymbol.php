<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Symbol;

use Amasiye\Ppphp\Semantic\Generic\GenericDeclarationEntry;
use Amasiye\Ppphp\Semantic\Type\NamedType;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Source\Span;

final class ClassSymbol
{
    /** @var array<string, MethodSymbol> */
    private array $recordedMethods = [];

    /** @var array<string, PropertySymbol> */
    private array $recordedProperties = [];

    /** @var array<string, ClassConstantSymbol> */
    private array $recordedConstants = [];

    private ?GenericDeclarationEntry $genericState = null;

    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     * @param list<NamedType> $interfaceTypes
     * @param list<NamedType> $traitTypes
     */
    public function __construct(
        public readonly string $fullyQualifiedName,
        public readonly string $namespace,
        public readonly string $kind,
        public readonly SourceFile $sourceFile,
        public readonly Span $declarationSpan,
        public readonly Span $selectionSpan,
        public readonly ?string $parent = null,
        public readonly array $interfaces = [],
        public readonly array $traits = [],
        public readonly ?NamedType $parentType = null,
        public readonly array $interfaceTypes = [],
        public readonly array $traitTypes = [],
        public readonly bool $abstract = false,
        public readonly bool $final = false,
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

    public function declareConstant(ClassConstantSymbol $constant): void
    {
        $this->recordedConstants[strtolower($constant->name)] = $constant;
    }

    public function findConstant(string $name): ?ClassConstantSymbol
    {
        return $this->recordedConstants[strtolower($name)] ?? null;
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

    /** @var list<ClassConstantSymbol> */
    public array $constants {
        get => array_values($this->recordedConstants);
    }
}
