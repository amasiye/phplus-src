<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics\Interfaces;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;

interface DiagnosticRenderer
{
    public function render(DiagnosticBag $diagnostics, bool $includeDebug = false): string;
}
