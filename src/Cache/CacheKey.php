<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

use Amasiye\Ppphp\Support\CanonicalJson;

final class CacheKey
{
    public function __construct(public string $value)
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('A cache key must be a SHA-256 identity.');
        }
    }

    /** @param array<string, mixed> $inputs */
    public static function create(string $operation, array $inputs): self
    {
        return new self('sha256:' . hash('sha256', CanonicalJson::encode([
            'inputs' => $inputs,
            'operation' => $operation,
        ])));
    }

    public string $hex {
        get => substr($this->value, 7);
    }
}
