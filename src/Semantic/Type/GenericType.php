<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final class GenericType implements Type
{
    /** @param non-empty-list<Type> $arguments */
    public function __construct(
        public readonly AtomicType $base,
        public readonly array $arguments,
    ) {}

    public string $canonical {
        get => $this->base->canonical . '<' . implode(',', array_map(
            static fn (Type $argument): string => $argument->canonical,
            $this->arguments,
        )) . '>';
    }

    public bool $isNullable {
        get => false;
    }

    public bool $isUnknown {
        get {
            foreach ($this->arguments as $argument) {
                if ($argument->isUnknown) {
                    return true;
                }
            }

            return false;
        }
    }

    public function renderPhpDoc(): string
    {
        return $this->base->renderPhpDoc() . '<' . implode(', ', array_map(
            static fn (Type $argument): string => $argument->renderPhpDoc(),
            $this->arguments,
        )) . '>';
    }

    public function eraseToNative(): string
    {
        return $this->base->eraseToNative();
    }
}
