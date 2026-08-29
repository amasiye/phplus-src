<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final class NamedType implements Type
{
    public function __construct(
        public readonly string $text,
        public readonly bool $explicit = true,
    ) {}

    public bool $allowsNull {
        get => str_starts_with($this->text, '?')
            || in_array('null', array_map(strtolower(...), explode('|', $this->text)), true)
            || strtolower($this->text) === 'mixed';
    }
}
