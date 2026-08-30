<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Amasiye\Ppphp\Project\ProjectSource;

final readonly class OutputPlanEntry
{
    public function __construct(
        public ProjectSource $source,
        public string $outputPath,
        public string $relativeOutputPath,
        public OutputOperation $operation,
    ) {}
}
