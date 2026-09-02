<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Type;

use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\PropertySymbol;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

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
        public MemberResolutionStatus $status = MemberResolutionStatus::Found,
        /** @var list<string> */
        public array $unresolvedReceivers = [],
    ) {}
}
