<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\GenericType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class EditorReceiverType
{
    /** @param array<string, Type> $argumentsByParameter */
    public function __construct(
        public readonly ClassSymbol $class,
        public readonly array $argumentsByParameter = [],
    ) {}

    public Type $semanticType {
        get {
            $declaration = $this->class->genericDeclaration;
            $parameters = $declaration === null ? [] : $declaration->parameters;

            if ($parameters === []) {
                return new AtomicType($this->class->fullyQualifiedName, true);
            }

            return new GenericType(
                new AtomicType($this->class->fullyQualifiedName, true),
                array_map(
                    fn (Type $parameter): Type => $this->argumentsByParameter[$parameter->canonical]
                        ?? $parameter,
                    $parameters,
                ),
            );
        }
    }
}
