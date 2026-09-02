<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

final class ParseResult
{
    public function __construct(
        public readonly ?ParsedFile $parsedFile,
        public readonly DiagnosticBag $diagnostics,
    ) {
        if ($parsedFile === null && !$diagnostics->hasErrors) {
            throw new \InvalidArgumentException(
                'A parse result without a parsed file must contain an error diagnostic.',
            );
        }
    }

    public bool $isSuccessful {
        get => $this->parsedFile !== null && !$this->diagnostics->hasErrors;
    }

    public bool $hasErrors {
        get => $this->diagnostics->hasErrors;
    }
}
