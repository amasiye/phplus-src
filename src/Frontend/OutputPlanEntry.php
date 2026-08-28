<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend;

use Amasiye\Phplus\Project\ProjectSource;

final readonly class OutputPlanEntry
{
    public function __construct(
        public ProjectSource $source,
        public string $outputPath,
    ) {}
}
