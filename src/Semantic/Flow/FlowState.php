<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Flow;

use Amasiye\Ppphp\Semantic\Type\AtomicType;
use Amasiye\Ppphp\Semantic\Type\Interfaces\Type;
use Amasiye\Ppphp\Semantic\Type\UnionType;

final class FlowState
{
    /**
     * @param array<string, Type> $localTypes
     * @param array<string, true> $initializedProperties
     */
    public function __construct(
        private array $localTypes = [],
        private array $initializedProperties = [],
    ) {}

    public function copy(): self
    {
        return new self($this->localTypes, $this->initializedProperties);
    }

    public function recordLocal(string $name, Type $type): void
    {
        $this->localTypes[$name] = $type;
    }

    public function resolveLocal(string $name): ?Type
    {
        return $this->localTypes[$name] ?? null;
    }

    public function recordPropertyInitialization(string $name): void
    {
        $this->initializedProperties[strtolower($name)] = true;
    }

    public function isPropertyInitialized(string $name): bool
    {
        return isset($this->initializedProperties[strtolower($name)]);
    }

    /** @var array<string, Type> */
    public array $locals {
        get => $this->localTypes;
    }

    /** @var list<string> */
    public array $initializedPropertyNames {
        get => array_keys($this->initializedProperties);
    }

    /** @param non-empty-list<FlowState> $states */
    public static function join(array $states): self
    {
        $first = $states[0];
        $localNames = [];
        $propertyNames = array_fill_keys($first->initializedPropertyNames, true);

        foreach ($states as $state) {
            foreach (array_keys($state->localTypes) as $name) {
                $localNames[$name] = true;
            }

            $propertyNames = array_intersect_key(
                $propertyNames,
                array_fill_keys($state->initializedPropertyNames, true),
            );
        }

        $locals = [];

        foreach (array_keys($localNames) as $name) {
            $types = [];

            foreach ($states as $state) {
                $type = $state->resolveLocal($name);

                if ($type !== null) {
                    $types[$type->canonical] = $type;
                }
            }

            $locals[$name] = match (count($types)) {
                0 => new AtomicType('mixed'),
                1 => array_values($types)[0],
                default => new UnionType(array_values($types)),
            };
        }

        return new self($locals, $propertyNames);
    }
}
