<?php

declare(strict_types=1);

namespace Example\Mixed\Contracts;

use Example\Mixed\Infrastructure\LegacyUnavailable;

/**
 * @template T of Named
 */
interface Repository
{
    /**
     * @return T
     * @throws LegacyUnavailable
     */
    public function find(string $id);
}

interface Named
{
    public function name(): string;
}
