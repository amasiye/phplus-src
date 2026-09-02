<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Symbol\ParameterSymbol;
use PhpParser\Node\Arg;

final readonly class BoundCallArgument
{
    public function __construct(
        public Arg $argument,
        public ParameterSymbol $parameter,
    ) {}
}
