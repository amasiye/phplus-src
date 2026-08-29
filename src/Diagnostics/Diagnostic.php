<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;

final readonly class Diagnostic
{
    /**
     * @param list<DiagnosticLabel> $related
     * @param array<string, mixed> $debug
     */
    public function __construct(
        public DiagnosticCode $code,
        public Severity $severity,
        public string $title,
        public string $message,
        public ?DiagnosticLabel $primary = null,
        public array $related = [],
        public ?string $help = null,
        public array $debug = [],
    ) {}
}
