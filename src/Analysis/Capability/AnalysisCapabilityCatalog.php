<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Capability;

use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode as Code;

final class AnalysisCapabilityCatalog
{
    public const int VERSION = 1;

    /** @var list<AnalysisCapability> */
    public array $all {
        get {
            $capabilities = [
                $this->capability('syntax.php', 'PHP syntax parsing', CapabilityCategory::Syntax, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::InvalidPhpSyntax], 'syntax-php', 'PHP grammar is parsed in process.', 'complete'),
                $this->capability('syntax.extension', '++PHP extension syntax', CapabilityCategory::Syntax, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::InvalidExtensionSyntax, Code::UnsupportedExtensionSyntax], 'syntax-extension', 'Extension syntax is parsed and normalized before semantic analysis.', 'complete'),
                $this->capability('declarations.strict', 'Strict declaration enforcement', CapabilityCategory::Declarations, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::MissingParameterType, Code::MissingReturnType, Code::MissingPropertyType, Code::StrictTypesCannotBeDisabled], 'declarations-strict', '++PHP declaration policy is compiler owned.', 'complete'),
                $this->capability('types.aliases', 'Type aliases and names', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Partial, SupplementalCoverage::Partial, [Code::TypeDoesNotExist], 'types-aliases', 'Compiler-owned source names are resolved; external names remain supplemental.', '13b-external-type-resolution'),
                $this->capability('types.composites', 'Composite types', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::InvalidCompositeType, Code::IntersectionTypeIsNotSatisfied, Code::CompositeTypeIsNotAssignable], 'types-composites', 'Union and intersection validity and compiler-owned assignability are implemented.', 'complete'),
                $this->capability('types.assignability', 'General assignability', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::InitializerNotAssignableToDeclaredType, Code::AssignmentNotAssignableToDeclaredType, Code::NullNotAssignable], 'types-assignability', 'Bindings are compiler owned; arbitrary PHP expression flow is not complete.', '13b-expression-flow'),
                $this->capability('flow.locals', 'Local variable flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::LocalVariableNotDeclared, Code::LocalVariableMayBeUninitialized, Code::ReadonlyLocalCannotBeReassigned], 'flow-locals', 'Typed local declaration, initialization, mutation, and readonly flow are compiler owned.', 'complete'),
                $this->capability('flow.loops', 'Loop binding flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::LoopBindingTypeDoesNotMatch, Code::ReadonlyForeachBindingNotSupported], 'flow-loops', 'Typed for and foreach bindings are compiler owned.', 'complete'),
                $this->capability('flow.properties', 'Property flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::DynamicPropertyNotAllowed, Code::PropertyTypeDoesNotMatch], 'flow-properties', 'Dynamic writes are compiler owned; deep property value flow remains supplemental.', '13b-expression-flow'),
                $this->capability('flow.when', 'When-expression flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::WhenBranchDoesNotProduceValue, Code::WhenResultTypeDoesNotMatch], 'flow-when', 'When-expression control and value flow are compiler owned.', 'complete'),
                $this->capability('calls.arguments', 'Argument validation', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch], 'calls-arguments', 'Generic signature checks are compiler owned; general PHP argument compatibility is supplemental.', '13b-call-validation'),
                $this->capability('calls.returns', 'Return validation', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::BackendOnly, SupplementalCoverage::Complete, [Code::ReturnTypeDoesNotMatch, Code::NotAllPathsReturnValue], 'calls-returns', 'General return compatibility and path coverage are currently supplied by the backend.', '13b-return-flow'),
                $this->capability('calls.members', 'Member access', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::MethodDoesNotExist, Code::PropertyDoesNotExist], 'calls-members', 'Generic member types are compiler owned; general existence checks remain supplemental.', '13b-member-existence'),
                $this->capability('calls.builtins', 'Built-in function inference', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Boundary, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch], 'calls-builtins', 'Required collection transforms are modeled; broad built-in inference is supplemental.', '13b-builtin-models'),
                $this->capability('calls.dynamic', 'Dynamic invocation boundaries', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::UnsafeDynamicConstruct, Code::UncheckedCallBoundary], 'calls-dynamic', 'Genuinely dynamic invocation is classified by compiler policy.', 'complete'),
                $this->capability('generics.declarations', 'Generic declarations', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::DuplicateTypeParameter, Code::UnknownTypeParameter], 'generics-declarations', 'Generic identities and scopes are compiler owned.', 'complete'),
                $this->capability('generics.arity', 'Generic arity', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::GenericTypeArgumentCountDoesNotMatch, Code::GenericTypeArgumentsAreRequired], 'generics-arity', 'Native generic applications are checked by the compiler.', 'complete'),
                $this->capability('generics.bounds', 'Generic bounds', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::TypeArgumentDoesNotSatisfyBound, Code::InvalidGenericBound], 'generics-bounds', 'Nominal applied bounds are compiler owned.', 'complete'),
                $this->capability('generics.substitution', 'Generic substitution', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::GenericTypeArgumentCountDoesNotMatch], 'generics-substitution', 'Applied member substitution crosses inheritance, interfaces, traits, unions, and intersections.', 'complete'),
                $this->capability('generics.invariance', 'Generic invariance', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::GenericTypeIsInvariant], 'generics-invariance', 'Mutable generic types are invariant under compiler policy.', 'complete'),
                $this->capability('generics.dependent-bounds', 'Dependent generic bounds', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::TypeArgumentDoesNotSatisfyBound], 'generics-dependent-bounds', 'Bounds are substituted left to right by the compiler.', 'complete'),
                $this->capability('generics.this', 'Applied generic $this', CapabilityCategory::Generics, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::TypeArgumentDoesNotSatisfyBound], 'generics-this', 'Instance $this carries applied owner arguments and is absent from static scopes.', 'complete'),
                $this->capability('collections.typed-arrays', 'Typed arrays', CapabilityCategory::Collections, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::TypedArrayKeyTypeIsInvalid, Code::TypedArrayValueTypeDoesNotMatch, Code::TypedArrayKeyTypeDoesNotMatch], 'collections-typed-arrays', 'Typed array declarations, literals, and writes are compiler owned.', 'complete'),
                $this->capability('collections.list-shape', 'List-shape preservation', CapabilityCategory::Collections, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::OperationWouldBreakListShape], 'collections-list-shape', 'List-breaking operations are diagnosed by the compiler.', 'complete'),
                $this->capability('collections.transforms', 'Collection transforms', CapabilityCategory::Collections, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::TypedArrayValueTypeDoesNotMatch], 'collections-transforms', 'array_filter and array_values preserve structured element flow.', 'complete'),
                $this->capability('errors.declarations', 'Checked-error declarations', CapabilityCategory::CheckedErrors, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::NativeThrowsClauseRequired, Code::DuplicateErrorDeclaration], 'errors-declarations', 'Native throws declarations and callable policies are compiler owned.', 'complete'),
                $this->capability('errors.propagation', 'Checked-error propagation', CapabilityCategory::CheckedErrors, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::CheckedErrorNotHandled, Code::CheckedErrorCannotEscapeFileScope], 'errors-propagation', 'Error effects propagate through compiler-resolved calls and control flow.', 'complete'),
                $this->capability('errors.catches', 'Checked-error catches', CapabilityCategory::CheckedErrors, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::CaughtErrorNeverThrown, Code::ErrorCatchUnreachable], 'errors-catches', 'Catch validity, reachability, and ordering are compiler owned.', 'complete'),
                $this->capability('errors.override-covariance', 'Checked-error override covariance', CapabilityCategory::CheckedErrors, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::CheckedErrorDeclarationNotCovariant], 'errors-override-covariance', 'Override error contracts are compared by the compiler.', 'complete'),
                $this->capability('interop.ordinary-php', 'Ordinary PHP analysis', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch, Code::ReturnTypeDoesNotMatch], 'interop-ordinary-php', 'Declarations participate in compiler context; deep ordinary PHP bodies remain supplemental.', '13b-ordinary-php-core'),
                $this->capability('interop.composer-vendor', 'Composer and vendor context', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::BackendOnly, SupplementalCoverage::Complete, [Code::InvalidComposerConfiguration, Code::InvalidComposerAutoloadMapping], 'interop-composer-vendor', 'External autoload discovery and vendor inference are supplied by native project preparation and PHPStan.', '13c-portable-dependency-index'),
                $this->capability('interop.stubs', 'Configured stubs', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Partial, SupplementalCoverage::Complete, [Code::ConfiguredStubPathInvalid], 'interop-stubs', 'Stub syntax is compiler context; deep stub inference remains supplemental.', '13b-stub-symbols'),
                $this->capability('infrastructure.backend-failure', 'Backend failure handling', CapabilityCategory::Infrastructure, CapabilityRequirement::Optional, CompilerCoverage::BackendOnly, SupplementalCoverage::Complete, [Code::StaticAnalysisBackendFailed, Code::StaticAnalysisResultInvalid], 'infrastructure-backend-failure', 'Backend failures are mapped to stable compiler diagnostics.', 'complete'),
                ];

            usort($capabilities, static fn (AnalysisCapability $left, AnalysisCapability $right): int => $left->id <=> $right->id);

            return $capabilities;
        }
    }

    /** @var list<string> */
    public array $uncoveredRequiredCapabilityIds {
        get => array_values(array_map(
            static fn (AnalysisCapability $capability): string => $capability->id,
            array_filter(
                $this->all,
                static fn (AnalysisCapability $capability): bool => $capability->requirement !== CapabilityRequirement::Optional
                    && $capability->compilerCoverage !== CompilerCoverage::Complete,
            ),
        ));
    }

    public function renderMarkdownTable(): string
    {
        $lines = [
            '| Capability ID | Name | Category | Requirement | Compiler | Supplemental | Diagnostics | Fixture evidence | Notes | Migration slice |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($this->all as $capability) {
            $lines[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s | %s | %s | %s | `%s` |',
                $capability->id,
                str_replace('|', '\\|', $capability->name),
                $capability->category->value,
                $capability->requirement->value,
                $capability->compilerCoverage->value,
                $capability->supplementalCoverage->value,
                implode(', ', array_map(static fn (Code $code): string => "`{$code->value}`", $capability->diagnosticCodes)),
                implode(', ', array_map(static fn (string $id): string => "`{$id}`", $capability->fixtureEvidence)),
                str_replace('|', '\\|', $capability->notes),
                $capability->migrationSlice,
            );
        }

        return implode("\n", $lines);
    }

    /** @param list<Code> $diagnosticCodes */
    private function capability(
        string $id,
        string $name,
        CapabilityCategory $category,
        CapabilityRequirement $requirement,
        CompilerCoverage $compilerCoverage,
        SupplementalCoverage $supplementalCoverage,
        array $diagnosticCodes,
        string $fixtureId,
        string $notes,
        string $migrationSlice,
    ): AnalysisCapability {
        return new AnalysisCapability(
            $id,
            $name,
            $category,
            $requirement,
            $compilerCoverage,
            $supplementalCoverage,
            $diagnosticCodes,
            [$fixtureId],
            $notes,
            $migrationSlice,
        );
    }
}
