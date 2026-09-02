<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

use Amasiye\Ppphp\Support\CanonicalJson;

final class ProjectInputSnapshot
{
    /** @param array<string, mixed> $inputs */
    public function __construct(public array $inputs) {}

    public string $identity {
        get => 'sha256:' . hash('sha256', CanonicalJson::encode($this->inputs));
    }

    /** @param array<string, mixed> $additionalInputs */
    public function key(string $operation, array $additionalInputs = []): CacheKey
    {
        return CacheKey::create($operation, [
            'additional' => $additionalInputs,
            'snapshot' => $this->inputs,
        ]);
    }
}
