<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\PhpStan;

use Amasiye\Ppphp\Analysis\AnalysisProject;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\Severity;
use Amasiye\Ppphp\Source\Enumerations\FileKind;

final class PhpStanDiagnosticMapper
{
    public function map(PhpStanFinding $finding, AnalysisProject $project): ?Diagnostic
    {
        $file = $project->findByAnalysisPath($finding->path);

        if ($file === null) {
            return null;
        }

        if ($finding->identifier === 'throws.unusedType') {
            return null;
        }

        if (
            $file->kind === FileKind::Php
            && str_starts_with($finding->identifier ?? '', 'missingType.')
        ) {
            return null;
        }

        if (in_array($finding->identifier, [
            'missingType.iterableValue',
            'missingType.callable',
        ], true)) {
            return null;
        }

        [$code, $title, $help] = $this->resolveStageEightCategory($finding, $file->kind)
            ?? $this->resolveCategory($finding);
        $span = $file->sourceMap->resolveSpan($finding->line);
        $message = str_replace($finding->path, $file->sourceFile->displayPath, $finding->message);

        return new Diagnostic(
            $code,
            Severity::Error,
            $title,
            $message,
            new DiagnosticLabel($span, $message),
            help: $help,
            debug: [
                'backendIdentifier' => $finding->identifier,
                'backendIgnorable' => $finding->ignorable,
            ],
        );
    }

    /** @return array{DiagnosticCode, string, string} */
    private function resolveStageEightCategory(PhpStanFinding $finding, FileKind $kind): ?array
    {
        $identifier = strtolower($finding->identifier ?? '');
        $message = strtolower($finding->message);

        if ($identifier === 'missingtype.generics' && $kind === FileKind::Ppphp) {
            return [
                DiagnosticCode::GenericTypeArgumentsAreRequired,
                'Generic Type Arguments Are Required',
                'Apply the generic declaration with explicit ++PHP type arguments.',
            ];
        }

        if (str_contains($identifier, 'template') || str_starts_with($identifier, 'generics.')) {
            return [
                DiagnosticCode::GenericStaticAnalysisError,
                'Generic Static Analysis Error',
                'Correct the generic declaration or its inferred type relationship.',
            ];
        }

        if ($kind !== FileKind::Ppphp) {
            return null;
        }

        if (str_contains($message, 'list<')) {
            return [
                DiagnosticCode::OperationWouldBreakListShape,
                'Operation Would Break List Shape',
                'Preserve contiguous integer keys and the declared list element type.',
            ];
        }

        if (str_contains($identifier, 'offsetaccess')) {
            return [
                DiagnosticCode::TypedArrayKeyTypeDoesNotMatch,
                'Typed Array Key Type Does Not Match',
                'Use an offset accepted by the declared typed-array key contract.',
            ];
        }

        if (str_contains($message, 'array<')) {
            return [
                DiagnosticCode::TypedArrayValueTypeDoesNotMatch,
                'Typed Array Value Type Does Not Match',
                'Preserve the declared typed-array key and value contract.',
            ];
        }

        if (preg_match_all('/\b[A-Z_a-z\\\\][A-Z_a-z0-9\\\\]*<[^>]+>/', $finding->message) >= 2) {
            return [
                DiagnosticCode::GenericTypeIsInvariant,
                'Generic Type Is Invariant',
                'Use the exact applied generic type required by the declaration.',
            ];
        }

        return null;
    }

    /** @return array{DiagnosticCode, string, string} */
    private function resolveCategory(PhpStanFinding $finding): array
    {
        if ($this->reportsNullMismatch($finding)) {
            return [DiagnosticCode::NullNotAssignable, 'Null Is Not Assignable', 'Make the receiving type nullable or avoid passing null.'];
        }

        if (
            str_starts_with($finding->identifier ?? '', 'missingType.')
            && !in_array($finding->identifier, [
                'missingType.checkedException',
                'missingType.parameter',
                'missingType.return',
                'missingType.property',
            ], true)
        ) {
            return [
                DiagnosticCode::ImplicitMixedNotAllowed,
                'Implicit Mixed Is Not Allowed',
                'Make the broad type explicit at the declaration boundary.',
            ];
        }

        return match ($finding->identifier) {
            'missingType.checkedException' => [DiagnosticCode::CheckedErrorNotHandled, 'Checked Error Is Not Handled', 'Catch the checked error or declare it on the enclosing callable.'],
            'throws.notCovariant' => [DiagnosticCode::CheckedErrorDeclarationNotCovariant, 'Checked Error Declaration Is Not Covariant', 'Narrow the child throws contract to types allowed by every inherited contract.'],
            'throws.notThrowable',
            'catch.notThrowable' => [DiagnosticCode::ErrorTypeNotThrowable, 'Error Type Is Not Throwable', 'Use a class or interface that implements Throwable.'],
            'catch.neverThrown' => [DiagnosticCode::CaughtErrorNeverThrown, 'Caught Error Is Never Thrown', 'Remove the unreachable catch or correct the throwing contract.'],
            'catch.alreadyCaught' => [DiagnosticCode::ErrorCatchUnreachable, 'Error Catch Is Unreachable', 'Move the narrower catch before the broader catch or remove it.'],
            'missingType.parameter' => [DiagnosticCode::MissingParameterType, 'Missing Parameter Type', 'Add an explicit native parameter type.'],
            'missingType.return' => [DiagnosticCode::MissingReturnType, 'Missing Return Type', 'Add an explicit native return type.'],
            'missingType.property' => [DiagnosticCode::MissingPropertyType, 'Missing Property Type', 'Add an explicit native property type.'],
            'argument.type' => [DiagnosticCode::ArgumentTypeDoesNotMatch, 'Argument Type Does Not Match', 'Pass a value compatible with the declared parameter type.'],
            'return.type' => [DiagnosticCode::ReturnTypeDoesNotMatch, 'Return Type Does Not Match', 'Return a value compatible with the callable return type.'],
            'return.missing' => [DiagnosticCode::NotAllPathsReturnValue, 'Not All Paths Return A Value', 'Return a compatible value on every reachable path.'],
            'method.notFound' => [DiagnosticCode::MethodDoesNotExist, 'Method Does Not Exist', 'Call a method declared by the resolved receiver type.'],
            'property.notFound' => [DiagnosticCode::PropertyDoesNotExist, 'Property Does Not Exist', 'Use a property declared by the resolved receiver type.'],
            'class.notFound' => [DiagnosticCode::TypeDoesNotExist, 'Type Does Not Exist', 'Declare or import the referenced type.'],
            'function.notFound' => [DiagnosticCode::FunctionDoesNotExist, 'Function Does Not Exist', 'Declare or import the referenced function.'],
            'assign.propertyType' => [DiagnosticCode::PropertyTypeDoesNotMatch, 'Property Type Does Not Match', 'Assign a value compatible with the declared property type.'],
            default => [DiagnosticCode::StaticAnalysisError, 'Static Analysis Error', 'Correct the reported type or symbol error.'],
        };
    }

    private function reportsNullMismatch(PhpStanFinding $finding): bool
    {
        if (!in_array($finding->identifier, ['argument.type', 'return.type', 'assign.propertyType'], true)) {
            return false;
        }

        return preg_match('/(?:null given|returns null|accept null)(?:\.|$)/i', $finding->message) === 1;
    }
}
