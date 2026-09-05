<?php

declare(strict_types=1);

namespace Tests\Support\FrameworkIntegrationSpike;

/** In-memory lifecycle experiment; deliberately NOT a replacement for production transactions. */
final class GenerationSelection
{
    /** @var array<string, string> output path => content identity */
    public private(set) array $active = [];

    /**
     * Stage a candidate; framework preparation must succeed before it becomes selectable.
     * @param array<string, string> $candidate
     * @param callable(array<string, string>): void $prepare
     */
    public function publish(array $candidate, callable $prepare): void
    {
        $prepare($candidate);
        $this->active = $candidate;
    }

    /**
     * @param array<string, string> $candidate
     * @return list<string> exact previously owned resources retired by this candidate
     */
    public function findStale(array $candidate): array
    {
        $stale = array_keys(array_diff_key($this->active, $candidate));
        sort($stale, SORT_STRING);
        return $stale;
    }
}
