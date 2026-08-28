<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class OutputPlanResult
{
    public function __construct(
        public ?OutputPlan $plan,
        public DiagnosticBag $diagnostics,
    ) {
        if (($plan === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('Output plan result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->plan !== null && !$this->diagnostics->hasErrors();
    }
}
