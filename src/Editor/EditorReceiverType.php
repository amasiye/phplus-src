<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class EditorReceiverType
{
    /** @param array<string, Type> $argumentsByParameter */
    public function __construct(
        public ClassSymbol $class,
        public array $argumentsByParameter = [],
    ) {}
}
