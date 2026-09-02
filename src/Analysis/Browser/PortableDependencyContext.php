<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

final readonly class PortableDependencyContext
{
    public function __construct(
        public string $manifestPath,
        public string $sha256,
    ) {
        if ($manifestPath === '' || strlen($manifestPath) > 4096) {
            throw new \InvalidArgumentException('The portable dependency manifest path is invalid.');
        }

        $hash = str_starts_with($sha256, 'sha256:') ? substr($sha256, 7) : $sha256;

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('The portable dependency manifest hash must be SHA-256.');
        }
    }
}
