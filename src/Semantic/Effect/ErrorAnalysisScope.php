<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Effect;

final readonly class ErrorAnalysisScope
{
    /** @param array<string, list<string>> $variableTypes */
    public function __construct(
        public string $kind,
        public ?CallableErrorContract $contract,
        public ?string $currentClass,
        public array $variableTypes = [],
    ) {}

    /** @param list<string> $types */
    public function includeVariable(string $name, array $types): self
    {
        $variables = $this->variableTypes;
        $variables[$name] = $types;

        return new self($this->kind, $this->contract, $this->currentClass, $variables);
    }
}
