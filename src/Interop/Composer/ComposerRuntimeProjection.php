<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Composer;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class ComposerRuntimeProjection
{
    /** @param list<ComposerRuntimeMapping> $unprojectedMappings */
    public function __construct(
        public readonly ?string $configurationPath,
        public readonly ?string $originalContents,
        public readonly ?string $projectedContents,
        public readonly DiagnosticBag $diagnostics,
        public readonly array $unprojectedMappings = [],
    ) {}

    public bool $isSuccessful {
        get => $this->projectedContents !== null && !$this->diagnostics->hasErrors;
    }

    public bool $isChanged {
        get => $this->isSuccessful && $this->originalContents !== $this->projectedContents;
    }
}
