<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;
use Atatusoft\Ppphp\Diagnostics\Enumerations\Severity;

final readonly class DiagnosticDefinition
{
    public function __construct(
        public DiagnosticCode $code,
        public DiagnosticFamily $family,
        public DiagnosticStatus $status,
        public Severity $severity,
        public string $title,
    ) {}
}
