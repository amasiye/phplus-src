<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class ErrorAnalysisScope
{
    /** @param array<string, Type> $variableTypes */
    public function __construct(
        public string $kind,
        public ?CallableErrorContract $contract,
        public ?string $currentClass,
        public array $variableTypes = [],
    ) {}

    public function includeVariable(string $name, Type $type): self
    {
        $variables = $this->variableTypes;
        $variables[$name] = $type;

        return new self($this->kind, $this->contract, $this->currentClass, $variables);
    }
}
