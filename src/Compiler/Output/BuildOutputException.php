<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

final class BuildOutputException extends \RuntimeException
{
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        public readonly string $diagnosticTitle,
        string $message,
        public readonly ?string $diagnosticHelp = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
