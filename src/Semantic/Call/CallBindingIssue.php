<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use PhpParser\Node\Arg;

final readonly class CallBindingIssue
{
    public function __construct(
        public CallBindingIssueKind $kind,
        public string $message,
        public ?Arg $argument = null,
    ) {}
}
