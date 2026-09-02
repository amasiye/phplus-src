<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Transpilation;

final readonly class GeneratedPhp
{
    /** @param list<SourceEdit> $appliedEdits */
    public function __construct(
        public string $contents,
        public GeneratedSourceMap $sourceMap,
        public array $appliedEdits,
    ) {}
}
