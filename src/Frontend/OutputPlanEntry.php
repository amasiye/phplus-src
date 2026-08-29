<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Frontend;

use Amasiye\Ppphp\Frontend\Enumerations\OutputOperation;
use Amasiye\Ppphp\Project\ProjectSource;

final readonly class OutputPlanEntry
{
    public function __construct(
        public ProjectSource $source,
        public string $outputPath,
        public OutputOperation $operation,
    ) {}
}
