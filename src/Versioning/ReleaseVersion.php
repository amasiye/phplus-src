<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Versioning;

use Amasiye\Ppphp\Versioning\Enumerations\ReleaseChannel;

final readonly class ReleaseVersion implements \Stringable
{
    private const string GRAMMAR = '/\A(?:(?<development>dev)-)?(?<year>[0-9]{4})\.(?<quarter>[1-4])\.(?<release>[1-9][0-9]*)(?:-rc-(?<candidate>[1-9][0-9]*))?\z/D';

    public string $core;

    public bool $isStable;

    public bool $isReleaseCandidate;

    public bool $isDevelopment;

    public bool $isPrerelease;

    private function __construct(
        public string $canonical,
        public int $year,
        public int $quarter,
        public int $releaseIncrement,
        public ReleaseChannel $channel,
        public ?int $candidateIncrement,
    ) {
        $this->core = sprintf('%04d.%d.%d', $year, $quarter, $releaseIncrement);
        $this->isStable = $channel === ReleaseChannel::Stable;
        $this->isReleaseCandidate = $channel === ReleaseChannel::ReleaseCandidate;
        $this->isDevelopment = $channel === ReleaseChannel::Development;
        $this->isPrerelease = !$this->isStable;
    }

    public static function parse(string $version): self
    {
        $matched = preg_match(self::GRAMMAR, $version, $matches, flags: PREG_UNMATCHED_AS_NULL);

        if ($matched !== 1 || ($matches['development'] !== null && $matches['candidate'] !== null)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a canonical ++PHP release version.', $version));
        }

        $year = (int) $matches['year'];
        $quarter = (int) $matches['quarter'];
        $releaseIncrement = self::parseIncrement($matches['release'], $version);
        $candidateIncrement = $matches['candidate'] === null
            ? null
            : self::parseIncrement($matches['candidate'], $version);
        $channel = $matches['development'] !== null
            ? ReleaseChannel::Development
            : ($candidateIncrement === null ? ReleaseChannel::Stable : ReleaseChannel::ReleaseCandidate);

        return new self(
            $version,
            $year,
            $quarter,
            $releaseIncrement,
            $channel,
            $candidateIncrement,
        );
    }

    public function toString(): string
    {
        return $this->canonical;
    }

    public function __toString(): string
    {
        return $this->canonical;
    }

    public function compareCore(self $other): int
    {
        return [$this->year, $this->quarter, $this->releaseIncrement]
            <=> [$other->year, $other->quarter, $other->releaseIncrement];
    }

    public function compareWithinChannel(self $other): int
    {
        if ($this->channel !== $other->channel) {
            throw new \LogicException('Release versions from different channels cannot be compared implicitly.');
        }

        $coreComparison = $this->compareCore($other);

        if ($coreComparison !== 0 || !$this->isReleaseCandidate) {
            return $coreComparison;
        }

        return ($this->candidateIncrement ?? 0) <=> ($other->candidateIncrement ?? 0);
    }

    public function compareCandidate(self $other): int
    {
        if (
            !$this->isReleaseCandidate
            || !$other->isReleaseCandidate
            || $this->compareCore($other) !== 0
        ) {
            throw new \LogicException('Release-candidate increments can be compared only within one exact release core.');
        }

        return ($this->candidateIncrement ?? 0) <=> ($other->candidateIncrement ?? 0);
    }

    private static function parseIncrement(string $increment, string $version): int
    {
        $parsed = filter_var($increment, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!is_int($parsed)) {
            throw new \InvalidArgumentException(sprintf('"%s" contains an unsupported release increment.', $version));
        }

        return $parsed;
    }
}
