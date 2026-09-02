<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Semantic\Symbol\SymbolTable;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\IntersectionType;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibility;
use Atatusoft\Ppphp\Semantic\Type\TypeCompatibilityResult;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Semantic\Type\TypedArrayType;
use Atatusoft\Ppphp\Semantic\Type\UnionType;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;

final readonly class GenericCallInference
{
    public function __construct(
        private TypeCompatibility $compatibility = new TypeCompatibility(),
    ) {}

    /**
     * @param list<array{parameter: Type, actual: Type}> $constraints
     */
    public function infer(
        CallableContract $contract,
        array $constraints,
        ?Type $expectedType = null,
        ?SymbolTable $symbols = null,
    ): GenericCallInferenceResult {
        $substitutions = $contract->receiverSubstitutions;
        $conflicting = false;

        foreach ($constraints as $constraint) {
            $this->collect(
                $constraint['parameter'],
                $constraint['actual'],
                $substitutions,
                $conflicting,
            );
        }

        if ($expectedType !== null && $contract->returnType !== null) {
            $this->collect($contract->returnType, $expectedType, $substitutions, $conflicting);
        }

        $complete = !$conflicting;

        $genericParameters = $contract->genericDeclaration === null
            ? []
            : $contract->genericDeclaration->parameters;

        foreach ($genericParameters as $parameter) {
            $argument = $substitutions[$parameter->canonical] ?? null;

            if ($argument === null) {
                $complete = false;
                continue;
            }

            if ($parameter->bound !== null
                && $this->compatibility->compare($parameter->bound, $argument, $symbols) === TypeCompatibilityResult::Incompatible) {
                unset($substitutions[$parameter->canonical]);
                $conflicting = true;
                $complete = false;
            }
        }

        return new GenericCallInferenceResult($substitutions, $complete, $conflicting);
    }

    /** @param array<string, Type> $substitutions */
    private function collect(Type $parameter, Type $actual, array &$substitutions, bool &$conflicting): void
    {
        if ($parameter instanceof TypeParameter) {
            $existing = $substitutions[$parameter->canonical] ?? null;

            if ($existing === null) {
                $substitutions[$parameter->canonical] = $actual;
            } elseif ($existing->canonical !== $actual->canonical) {
                $conflicting = true;
            }

            return;
        }

        if ($parameter instanceof GenericType && $actual instanceof GenericType
            && $parameter->base->canonical === $actual->base->canonical) {
            foreach ($parameter->arguments as $index => $argument) {
                if (isset($actual->arguments[$index])) {
                    $this->collect($argument, $actual->arguments[$index], $substitutions, $conflicting);
                }
            }

            return;
        }

        if ($parameter instanceof TypedArrayType && $actual instanceof TypedArrayType) {
            $this->collect($parameter->keyType, $actual->keyType, $substitutions, $conflicting);
            $this->collect($parameter->valueType, $actual->valueType, $substitutions, $conflicting);

            return;
        }

        if (($parameter instanceof UnionType || $parameter instanceof IntersectionType)
            && ($actual instanceof UnionType || $actual instanceof IntersectionType)
            && count($parameter->members) === count($actual->members)) {
            foreach ($parameter->members as $index => $member) {
                $this->collect($member, $actual->members[$index], $substitutions, $conflicting);
            }
        }
    }
}
