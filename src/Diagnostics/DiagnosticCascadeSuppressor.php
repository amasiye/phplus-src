<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Amasiye\Ppphp\Source\Span;
use Amasiye\Ppphp\Support\Path;

final class DiagnosticCascadeSuppressor
{
    /** @param iterable<Diagnostic> $diagnostics */
    public function suppress(iterable $diagnostics): DiagnosticBag
    {
        $items = is_array($diagnostics) ? array_values($diagnostics) : iterator_to_array($diagnostics, false);
        $result = [];

        foreach ($items as $candidate) {
            if ($candidate->origin === DiagnosticOrigin::Compiler || !$this->isSuppressed($candidate, $items)) {
                $result[] = $candidate;
            }
        }

        return new DiagnosticBag($result);
    }

    /** @param list<Diagnostic> $diagnostics */
    private function isSuppressed(Diagnostic $candidate, array $diagnostics): bool
    {
        foreach ($diagnostics as $compiler) {
            if ($compiler->origin !== DiagnosticOrigin::Compiler || !$this->describesSameIssue($compiler, $candidate)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function describesSameIssue(Diagnostic $compiler, Diagnostic $backend): bool
    {
        if (!$this->spansOverlap($compiler->primary?->span, $backend->primary?->span)) {
            return false;
        }

        if ($compiler->identity !== null && $compiler->identity === $backend->identity) {
            return true;
        }

        if ($compiler->code === $backend->code) {
            return true;
        }

        return in_array($backend->code, $this->resolveFallbacks($compiler->code), true);
    }

    /** @return list<DiagnosticCode> */
    private function resolveFallbacks(DiagnosticCode $code): array
    {
        return match ($code) {
            DiagnosticCode::LocalVariableNotDeclared,
            DiagnosticCode::MethodDoesNotExist,
            DiagnosticCode::PropertyDoesNotExist,
            DiagnosticCode::TypeDoesNotExist,
            DiagnosticCode::FunctionDoesNotExist => [DiagnosticCode::StaticAnalysisError],
            DiagnosticCode::GenericTypeArgumentCountDoesNotMatch,
            DiagnosticCode::TypeArgumentDoesNotSatisfyBound,
            DiagnosticCode::GenericTypeArgumentsAreRequired,
            DiagnosticCode::TypeIsNotGeneric,
            DiagnosticCode::GenericTypeIsInvariant => [DiagnosticCode::GenericStaticAnalysisError],
            default => [],
        };
    }

    private function spansOverlap(?Span $left, ?Span $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        if (Path::buildComparisonKey($left->sourceFile->path) !== Path::buildComparisonKey($right->sourceFile->path)) {
            return false;
        }

        if ($left->isEmpty || $right->isEmpty) {
            return $left->start->offset === $right->start->offset;
        }

        return $left->start->offset < $right->end->offset
            && $right->start->offset < $left->end->offset;
    }
}
