<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Frontend\Normalization;

use Amasiye\Phplus\Source\SourceFile;

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
