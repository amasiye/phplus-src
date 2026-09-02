<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Extensions;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Frontend\Ast\ExtensionSyntaxIndex;
use Atatusoft\Ppphp\Frontend\Normalization\NormalizationPlan;

final readonly class ExtensionParseResult
{
    public function __construct(
        public ExtensionSyntaxIndex $index,
        public NormalizationPlan $normalizationPlan,
        public DiagnosticBag $diagnostics,
    ) {}
}
