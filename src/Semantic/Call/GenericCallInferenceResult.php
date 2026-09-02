<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class GenericCallInferenceResult
{
    /** @param array<string, Type> $substitutions */
    public function __construct(
        public array $substitutions,
        public bool $complete,
        public bool $conflicting = false,
    ) {}
}
