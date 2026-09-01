<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

final readonly class ResolvedCallable
{
    public function __construct(
        public CallableResolutionStatus $status,
        public ?CallableContract $contract = null,
        public string $provenance = '',
    ) {}

    public static function found(CallableContract $contract): self
    {
        return new self(CallableResolutionStatus::Found, $contract, $contract->origin->value);
    }
}
