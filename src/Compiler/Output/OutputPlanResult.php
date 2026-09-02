<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class OutputPlanResult
{
    public function __construct(
        public readonly ?OutputPlan $plan,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($plan === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('Output plan result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->plan !== null && !$this->diagnostics->hasErrors;
    }
}
