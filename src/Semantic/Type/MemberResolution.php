<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Type;

use Amasiye\Ppphp\Semantic\Symbol\ClassSymbol;
use Amasiye\Ppphp\Semantic\Symbol\MethodSymbol;
use Amasiye\Ppphp\Semantic\Symbol\PropertySymbol;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class MemberResolution
{
    /**
     * @param list<array{
     *     member: MethodSymbol|PropertySymbol,
     *     owner: ClassSymbol,
     *     receiver: Type,
     *     calledReceiver: Type,
     *     substitutions: array<string, Type>
     * }> $targets
     */
    public function __construct(
        public array $targets,
        public bool $complete,
    ) {}
}
