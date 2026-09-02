<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseSchema
{
    public const string ARTIFACT_NAME = 'ppphp.schema.json';

    private const string RELEASE_DOWNLOADS = 'https://github.com/atatusoft-ltd/ppphp-src/releases/download';

    public string $tag;

    public string $url;

    public function __construct(public ReleaseVersion $version)
    {
        $this->tag = $version->canonical;
        $this->url = sprintf(
            '%s/%s/%s',
            self::RELEASE_DOWNLOADS,
            $version->canonical,
            self::ARTIFACT_NAME,
        );
    }

    public function validatePublishedIdentity(string $tag, string $schemaUrl): void
    {
        $tagVersion = ReleaseVersion::parse($tag);

        if ($tagVersion->canonical !== $this->version->canonical) {
            throw new \InvalidArgumentException('The release tag does not match the schema version.');
        }

        if ($schemaUrl !== $this->url) {
            throw new \InvalidArgumentException('The schema URL is not the exact immutable release URL.');
        }
    }
}
