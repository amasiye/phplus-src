<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Interop\Stub;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class StubLoadResult
{
    public function __construct(
        public ?StubRepository $repository,
        public DiagnosticBag $diagnostics,
    ) {
        if (($repository === null) === !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException('Stub load result state does not match its diagnostics.');
        }
    }

    public function isSuccessful(): bool
    {
        return $this->repository !== null && !$this->diagnostics->hasErrors();
    }
}
