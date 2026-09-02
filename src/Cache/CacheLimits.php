<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

final readonly class CacheLimits
{
    /**
     * @param positive-int $maximumRecordBytes
     * @param positive-int $maximumBlobBytes
     * @param positive-int $maximumCacheBytes
     * @param positive-int $maximumRecordCount
     * @param positive-int $maximumBlobCount
     * @param positive-int $maximumJsonDepth
     * @param int<0, max> $activeWriteGraceSeconds
     */
    public function __construct(
        public int $maximumRecordBytes = 2_097_152,
        public int $maximumBlobBytes = 33_554_432,
        public int $maximumCacheBytes = 268_435_456,
        public int $maximumRecordCount = 6_000,
        public int $maximumBlobCount = 4_096,
        public int $maximumJsonDepth = 64,
        public int $activeWriteGraceSeconds = 300,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < ($name === 'activeWriteGraceSeconds' ? 0 : 1)) {
                throw new \InvalidArgumentException('Compiler cache limits must be positive.');
            }
        }
    }
}
