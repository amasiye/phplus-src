<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Versioning;

use Amasiye\Ppphp\Versioning\Enumerations\ReleaseChannel;

final readonly class ReleaseSelector
{
    /**
     * @param iterable<ReleaseVersion> $availableVersions
     */
    public function select(
        iterable $availableVersions,
        ?string $requestedChannel = null,
        ?string $exactVersion = null,
    ): ReleaseVersion {
        $versions = is_array($availableVersions) ? $availableVersions : iterator_to_array($availableVersions, false);

        if ($exactVersion !== null) {
            $exact = ReleaseVersion::parse($exactVersion);

            if ($requestedChannel !== null && $this->parseChannel($requestedChannel) !== $exact->channel) {
                throw new \InvalidArgumentException(sprintf(
                    'Release "%s" does not belong to the requested "%s" channel.',
                    $exactVersion,
                    $requestedChannel,
                ));
            }

            foreach ($versions as $available) {
                if ($available->canonical === $exact->canonical) {
                    return $available;
                }
            }

            throw new \RuntimeException(sprintf('Release "%s" is not available.', $exactVersion));
        }

        $channel = $requestedChannel === null
            ? ReleaseChannel::Stable
            : $this->parseChannel($requestedChannel);
        $selected = null;

        foreach ($versions as $available) {
            if ($available->channel !== $channel) {
                continue;
            }

            if ($selected === null || $available->compareWithinChannel($selected) > 0) {
                $selected = $available;
            }
        }

        if ($selected === null) {
            throw new \RuntimeException(sprintf('No release is available in the "%s" channel.', $channel->value));
        }

        return $selected;
    }

    private function parseChannel(string $channel): ReleaseChannel
    {
        if ($channel === '') {
            throw new \InvalidArgumentException('The requested release channel cannot be empty.');
        }

        return ReleaseChannel::tryFrom($channel)
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a supported release channel.', $channel));
    }
}
