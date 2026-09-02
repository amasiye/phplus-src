<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Flow;

use Atatusoft\Ppphp\Semantic\Type\ExpressionTypeResolution;

final readonly class FlowOutcome
{
    /**
     * @param list<ExpressionTypeResolution|null> $returns
     * @param list<FlowState> $returnStates
     * @param list<FlowState> $breakStates
     */
    public function __construct(
        public ?FlowState $normalState,
        public array $returns = [],
        public bool $throws = false,
        public bool $breaks = false,
        public bool $continues = false,
        public bool $exits = false,
        public array $returnStates = [],
        public array $breakStates = [],
    ) {}

    public function mayCompleteNormally(): bool
    {
        return $this->normalState !== null;
    }

    public static function normal(FlowState $state): self
    {
        return new self($state);
    }
}
