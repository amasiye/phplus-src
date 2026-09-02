<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Compiler\Output\Enumerations\OutputOperation;
use Atatusoft\Ppphp\Project\ProjectSource;

final readonly class OutputPlanEntry
{
    public function __construct(
        public ProjectSource $source,
        public string $outputPath,
        public string $relativeOutputPath,
        public OutputOperation $operation,
    ) {}
}
