<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

final readonly class ParseResult
{
    public function __construct(
        private ?ParsedFile $parsedFile,
        private DiagnosticBag $diagnostics,
    ) {
        if ($parsedFile === null && !$diagnostics->hasErrors()) {
            throw new \InvalidArgumentException(
                'A parse result without a parsed file must contain an error diagnostic.',
            );
        }
    }

    public function parsedFile(): ?ParsedFile
    {
        return $this->parsedFile;
    }

    public function diagnostics(): DiagnosticBag
    {
        return $this->diagnostics;
    }

    public function isSuccessful(): bool
    {
        return $this->parsedFile !== null && !$this->diagnostics->hasErrors();
    }

    public function hasErrors(): bool
    {
        return $this->diagnostics->hasErrors();
    }
}
