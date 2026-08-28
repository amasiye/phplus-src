<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics;

use Amasiye\Phplus\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Phplus\Diagnostics\Enumerations\Severity;

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
