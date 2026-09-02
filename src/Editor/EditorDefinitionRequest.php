<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Editor;

final readonly class EditorDefinitionRequest
{
    public const int VERSION = 1;

    public const int MAXIMUM_CONTENT_BYTES = 2_097_152;

    public const int MAXIMUM_PATH_BYTES = 4096;

    public const int MAXIMUM_TRANSPORT_BYTES = (self::MAXIMUM_CONTENT_BYTES + self::MAXIMUM_PATH_BYTES) * 6 + 16_384;

    public function __construct(
        public string $path,
        public string $contents,
        public int $offset,
    ) {
        if ($path === '' || strlen($path) > self::MAXIMUM_PATH_BYTES) {
            throw new \InvalidArgumentException('The editor document path must contain between 1 and 4096 bytes.');
        }

        if (strlen($contents) > self::MAXIMUM_CONTENT_BYTES) {
            throw new \InvalidArgumentException('The editor document exceeds the two-megabyte request limit.');
        }

        if ($offset < 0 || $offset > strlen($contents)) {
            throw new \InvalidArgumentException('The editor definition offset is outside the document.');
        }
    }
}
