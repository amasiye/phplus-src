<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticFamily;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;

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
