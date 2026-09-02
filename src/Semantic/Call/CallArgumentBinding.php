<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

final readonly class CallArgumentBinding
{
    /**
     * @param list<BoundCallArgument> $arguments
     * @param list<CallBindingIssue> $issues
     */
    public function __construct(
        public array $arguments,
        public array $issues,
        public bool $containsUnpacking,
    ) {}
}
