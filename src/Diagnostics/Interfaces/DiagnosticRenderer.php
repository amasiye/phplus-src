<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics\Interfaces;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;

interface DiagnosticRenderer
{
    public function render(DiagnosticBag $diagnostics, bool $includeDebug = false): string;
}
