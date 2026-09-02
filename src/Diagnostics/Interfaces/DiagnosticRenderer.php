<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics\Interfaces;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;

interface DiagnosticRenderer
{
    public function render(DiagnosticBag $diagnostics, bool $includeDebug = false): string;
}
