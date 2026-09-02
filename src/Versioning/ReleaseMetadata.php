<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

use Atatusoft\Ppphp\Versioning\Enumerations\ReleaseChannel;

final readonly class ReleaseMetadata
{
    public function __construct(
        public int $formatVersion,
        public ReleaseVersion $version,
        public ReleaseChannel $channel,
        public string $tag,
        public bool $prerelease,
        public string $schemaAsset,
        public string $schemaUrl,
        public string $schemaSha256,
        public string $releaseNotes,
    ) {}
}
