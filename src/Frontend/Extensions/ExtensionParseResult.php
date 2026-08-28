<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Extensions;

use Amasiye\Phplus\Diagnostics\DiagnosticBag;
use Amasiye\Phplus\Frontend\Ast\ExtensionSyntaxIndex;
use Amasiye\Phplus\Frontend\Normalization\NormalizationPlan;

final readonly class ExtensionParseResult
{
    public function __construct(
        public ExtensionSyntaxIndex $index,
        public NormalizationPlan $normalizationPlan,
        public DiagnosticBag $diagnostics,
    ) {}
}
