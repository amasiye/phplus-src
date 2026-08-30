<?php

declare(strict_types=1);

namespace Example\Mixed\Infrastructure;

final class LegacyUnavailable extends \RuntimeException
{
}

final readonly class LegacyRecord
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}

final class LegacyGateway
{
    public function fetch(string $id): LegacyRecord
    {
        if ($id === 'offline') {
            throw new LegacyUnavailable('The legacy gateway is unavailable.');
        }

        return new LegacyRecord('Ada', 36);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['php', 'ppphp'];
    }

    /** @return array<string, int> */
    public function scores(): array
    {
        return ['quality' => 100];
    }
}
