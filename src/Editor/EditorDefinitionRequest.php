<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Editor;

final readonly class EditorDefinitionRequest
{
    public const int VERSION = 1;

    public const int MAXIMUM_CONTENT_BYTES = 2_097_152;

    public function __construct(
        public string $path,
        public string $contents,
        public int $offset,
    ) {
        if ($path === '' || strlen($path) > 4096) {
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
