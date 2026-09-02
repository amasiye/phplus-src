<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Frontend\Normalization;

use Atatusoft\Ppphp\Source\SourceFile;

final readonly class NormalizedSource
{
    public function __construct(
        public SourceFile $originalSource,
        public string $contents,
        public SourceMap $sourceMap,
    ) {
        if (strlen($contents) !== $originalSource->length) {
            throw new \InvalidArgumentException('Normalized source must preserve byte length.');
        }
    }
}
