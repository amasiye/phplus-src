<?php

declare(strict_types=1);

namespace Example\Mixed\Infrastructure;

final class LegacyGateway
{
    /**
     * @throws LegacyUnavailable
     */
    public function fetch(string $id): LegacyRecord
    {
    }

    /** @return list<string> */
    public function tags(): array
    {
    }

    /** @return array<string, int> */
    public function scores(): array
    {
    }
}
