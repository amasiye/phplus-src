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

        if ($file->kind === FileKind::Php && str_starts_with($finding->identifier ?? '', 'missingType.')) {
            return null;
        }

        if (in_array($finding->identifier, [
            'missingType.iterableValue',
            'missingType.callable',
            'missingType.generics',
        ], true)) {
            return null;
        }

        [$code, $title, $help] = $this->resolveCategory($finding);
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
    private function resolveCategory(PhpStanFinding $finding): array
    {
        if ($this->reportsNullMismatch($finding)) {
            return [DiagnosticCode::NullNotAssignable, 'Null Is Not Assignable', 'Make the receiving type nullable or avoid passing null.'];
        }

        if (
            str_starts_with($finding->identifier ?? '', 'missingType.')
            && !in_array($finding->identifier, [
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
