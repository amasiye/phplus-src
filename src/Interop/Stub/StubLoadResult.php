<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Stub;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class StubLoadResult
{
    public function __construct(
        public readonly ?StubRepository $repository,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if (($repository === null) === !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException('Stub load result state does not match its diagnostics.');
        }
    }

    public bool $isSuccessful {
        get => $this->repository !== null && !$this->diagnostics->hasErrors;
    }
}
