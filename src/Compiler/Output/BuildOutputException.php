<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

final class BuildOutputException extends \RuntimeException
{
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        string $message,
        public readonly ?string $diagnosticHelp = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
