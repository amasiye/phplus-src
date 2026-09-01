<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Semantic\Call;

use Amasiye\Ppphp\Semantic\Symbol\ParameterSymbol;
use PhpParser\Node\Arg;
use PhpParser\Node\VariadicPlaceholder;

final class CallArgumentBinder
{
    /** @param array<Arg|VariadicPlaceholder> $arguments */
    public function bind(CallableContract $contract, array $arguments): CallArgumentBinding
    {
        $bound = [];
        $issues = [];
        $used = [];
        $namedSeen = false;
        $unpacking = false;
        $nextPosition = 0;
        $variadic = $this->variadicParameter($contract->parameters);

        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg) {
                $unpacking = true;
                continue;
            }

            if ($argument->unpack) {
                $unpacking = true;
                continue;
            }

            if ($argument->name !== null) {
                $namedSeen = true;
                $name = $argument->name->toString();
                $parameter = $this->findNamed($contract->parameters, $name) ?? $variadic;

                if ($parameter === null) {
                    $issues[] = new CallBindingIssue(
                        CallBindingIssueKind::UnknownNamedArgument,
                        sprintf('The callable %s has no parameter named $%s.', $contract->identity, $name),
                        $argument,
                    );
                    continue;
                }

                if (!$parameter->variadic && isset($used[$parameter->position])) {
                    $issues[] = new CallBindingIssue(
                        CallBindingIssueKind::DuplicateNamedArgument,
                        sprintf('The parameter %s is bound more than once.', $parameter->name),
                        $argument,
                    );
                    continue;
                }

                $used[$parameter->position] = true;
                $bound[] = new BoundCallArgument($argument, $parameter);
                continue;
            }

            if ($namedSeen) {
                $issues[] = new CallBindingIssue(
                    CallBindingIssueKind::PositionalAfterNamed,
                    'A positional argument cannot follow a named argument.',
                    $argument,
                );
                continue;
            }

            while (isset($used[$nextPosition])) {
                $nextPosition++;
            }

            $parameter = $contract->parameters[$nextPosition] ?? $variadic;

            if ($parameter === null) {
                $issues[] = new CallBindingIssue(
                    CallBindingIssueKind::ArgumentCount,
                    sprintf('The callable %s received too many arguments.', $contract->identity),
                    $argument,
                );
                continue;
            }

            $used[$parameter->position] = true;
            $bound[] = new BoundCallArgument($argument, $parameter);

            if (!$parameter->variadic) {
                $nextPosition++;
            }
        }

        if (!$unpacking) {
            foreach ($contract->parameters as $parameter) {
                if (!$parameter->hasDefault && !$parameter->variadic && !isset($used[$parameter->position])) {
                    $issues[] = new CallBindingIssue(
                        CallBindingIssueKind::ArgumentCount,
                        sprintf('The required parameter %s was not provided to %s.', $parameter->name, $contract->identity),
                    );
                }
            }
        }

        return new CallArgumentBinding($bound, $issues, $unpacking);
    }

    /** @param list<ParameterSymbol> $parameters */
    private function findNamed(array $parameters, string $name): ?ParameterSymbol
    {
        foreach ($parameters as $parameter) {
            if (ltrim($parameter->name, '$') === $name) {
                return $parameter;
            }
        }

        return null;
    }

    /** @param list<ParameterSymbol> $parameters */
    private function variadicParameter(array $parameters): ?ParameterSymbol
    {
        foreach ($parameters as $parameter) {
            if ($parameter->variadic) {
                return $parameter;
            }
        }

        return null;
    }
}
