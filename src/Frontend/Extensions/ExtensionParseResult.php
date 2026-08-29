<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend\Extensions;

use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Amasiye\Ppphp\Frontend\Normalization\NormalizationPlan;

final readonly class ExtensionParseResult
{
    public function __construct(
        public ExtensionSyntaxIndex $index,
        public NormalizationPlan $normalizationPlan,
        public DiagnosticBag $diagnostics,
    ) {}
}
