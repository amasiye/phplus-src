<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Capability;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode as Code;

final class AnalysisCapabilityCatalog
{
    public const int VERSION = 4;

    /** @var list<AnalysisCapability> */
    public array $all {
        get {
            $capabilities = [
                $this->capability('syntax.php', 'PHP syntax parsing', CapabilityCategory::Syntax, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::InvalidPhpSyntax], 'syntax-php', 'PHP grammar is parsed in process.', 'complete'),
                $this->capability('syntax.extension', '++PHP extension syntax', CapabilityCategory::Syntax, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::InvalidExtensionSyntax, Code::UnsupportedExtensionSyntax], 'syntax-extension', 'Extension syntax is parsed and normalized before semantic analysis.', 'complete'),
                $this->capability('declarations.strict', 'Strict declaration enforcement', CapabilityCategory::Declarations, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::MissingParameterType, Code::MissingReturnType, Code::MissingPropertyType, Code::StrictTypesCannotBeDisabled], 'declarations-strict', '++PHP declaration policy is compiler owned.', 'complete'),
                $this->capability('types.name-resolution', 'PHP name resolution', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::TypeDoesNotExist, Code::FunctionDoesNotExist], ['types-name-resolution-import', 'types-name-resolution-missing', 'types-name-resolution-deferred'], 'Lexical names, imports, aliases, project declarations, stubs, and reviewed engine roots are compiler owned; unindexed dependencies and PHP global-function fallback remain deferred.', 'complete'),
                $this->capability('types.composites', 'Composite types', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::InvalidCompositeType, Code::IntersectionTypeIsNotSatisfied, Code::CompositeTypeIsNotAssignable], 'types-composites', 'Union and intersection validity and compiler-owned assignability are implemented.', 'complete'),
                $this->capability('types.assignability', 'General assignability', CapabilityCategory::TypeSystem, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::InitializerNotAssignableToDeclaredType, Code::AssignmentNotAssignableToDeclaredType, Code::NullNotAssignable], ['types-assignability', 'types-assignability-expression'], 'Bindings and compiler-owned expression results use explicit compatible, incompatible, or unknown outcomes.', 'complete'),
                $this->capability('flow.locals', 'Local variable flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::LocalVariableNotDeclared, Code::LocalVariableMayBeUninitialized, Code::ReadonlyLocalCannotBeReassigned], 'flow-locals', 'Typed local declaration, initialization, mutation, and readonly flow are compiler owned.', 'complete'),
                $this->capability('flow.loops', 'Loop binding flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Partial, [Code::LoopBindingTypeDoesNotMatch, Code::ReadonlyForeachBindingNotSupported], 'flow-loops', 'Typed for and foreach bindings are compiler owned.', 'complete'),
                $this->capability('flow.properties', 'Property flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::DynamicPropertyNotAllowed, Code::PropertyTypeDoesNotMatch, Code::PropertyMayBeUninitialized, Code::MemberWriteIsNotAllowed], ['flow-properties', 'flow-properties-assignment', 'flow-properties-initialization'], 'Typed writes, access contracts, backed storage, and definite construction are compiler owned.', 'complete'),
                $this->capability('flow.when', 'When-expression flow', CapabilityCategory::Flow, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::None, [Code::WhenBranchDoesNotProduceValue, Code::WhenResultTypeDoesNotMatch], 'flow-when', 'When-expression control and value flow are compiler owned.', 'complete'),
                $this->capability('flow.generators', 'Generator return flow', CapabilityCategory::Flow, CapabilityRequirement::Optional, CompilerCoverage::BackendOnly, SupplementalCoverage::Complete, [Code::ReturnTypeDoesNotMatch], 'flow-generators', 'Generator-specific yield and return contracts remain explicitly outside general Stage 13B return completeness.', 'future-generator-flow'),
                $this->capability('calls.arguments', 'Argument validation', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch, Code::ArgumentCountDoesNotMatch, Code::NamedArgumentDoesNotExist, Code::ArgumentMustBeReferenceable], ['calls-arguments-negative', 'calls-arguments-positive', 'calls-arguments-named'], 'Known source, PHP, stub, constructor, method, and intrinsic contracts share compiler-owned argument binding.', 'complete'),
                $this->capability('calls.returns', 'Return validation', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ReturnTypeDoesNotMatch, Code::NotAllPathsReturnValue], ['calls-returns-mismatch', 'calls-returns-paths', 'calls-returns-finally'], 'Return expressions and normal completion are checked by compiler-owned flow outcomes.', 'complete'),
                $this->capability('calls.members', 'Member access', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Mvp, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::MethodDoesNotExist, Code::PropertyDoesNotExist, Code::StaticMemberAccessIsInvalid, Code::MemberReadIsNotAllowed], ['calls-members-missing', 'calls-members-generic', 'calls-members-static'], 'Existence, generic substitution, access form, and visibility are compiler owned for known receivers.', 'complete'),
                $this->capability('calls.intrinsics', 'Reviewed intrinsic functions', CapabilityCategory::CallsAndMembers, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch], ['calls-intrinsics-negative', 'calls-intrinsics-collections'], 'A bounded process-free repository owns language-critical intrinsic contracts and collection transforms.', 'complete'),
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
                $this->capability('interop.ordinary-php-contracts', 'Ordinary PHP contracts', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch], ['interop-ordinary-php-contracts-negative', 'interop-ordinary-php-contracts-positive'], 'Native and compatible PHPDoc declarations contribute compiler-owned boundary contracts without ++PHP declaration rules.', 'complete'),
                $this->capability('interop.ordinary-php-bodies', 'Deep ordinary PHP body analysis', CapabilityCategory::Interoperability, CapabilityRequirement::Optional, CompilerCoverage::BackendOnly, SupplementalCoverage::Complete, [Code::ReturnTypeDoesNotMatch], 'interop-ordinary-php-bodies', 'Deep diagnostics inside ordinary PHP bodies remain supplemental.', 'complete'),
                $this->capability('interop.composer-vendor', 'Composer and vendor context', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::InvalidComposerConfiguration, Code::InvalidComposerAutoloadMapping, Code::InvalidInstalledComposerMetadata, Code::ComposerDependencyIndexLimitExceeded, Code::ComposerDependencySourceNotReadable, Code::ComposerDependencyDeclarationInvalid, Code::DependencyDeclarationContextUnavailable, Code::DependencyDeclarationAmbiguous, Code::DependencySourcePathUnsafe, Code::ArgumentTypeDoesNotMatch], 'interop-composer-vendor', 'Installed production metadata follows ordered PSR-4, PSR-0, files, classmap, exclusions, static includes, guarded fallbacks, aliases, and canonical-root trust without executing dependency code.', 'complete'),
                $this->capability('interop.portable-dependency-index', 'Portable dependency declaration index', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::PortableDependencyIndexInvalid, Code::ArgumentTypeDoesNotMatch], 'interop-portable-dependency-index', 'A verified source-free manifest and package shards restore the same compiler-owned dependency contracts without rescanning installed implementation source.', 'complete'),
                $this->capability('interop.stubs', 'Configured stubs', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ConfiguredStubPathInvalid, Code::StubContractConflict, Code::ArgumentTypeDoesNotMatch], ['interop-stubs-positive', 'interop-stubs-negative', 'interop-stubs-conflict'], 'Configured stubs contribute callable, member, generic, array, and error contracts without becoming build output.', 'complete'),
                $this->capability('interop.builtin-signatures', 'Broad PHP built-in signatures', CapabilityCategory::Interoperability, CapabilityRequirement::Boundary, CompilerCoverage::Complete, SupplementalCoverage::Complete, [Code::ArgumentTypeDoesNotMatch, Code::PhpSignaturePackageInvalid, Code::DeclarationConflictsWithPhpPlatform], 'interop-builtin-signatures', 'A verified target-version signature package supplies broad PHP core and extension declarations; reviewed intrinsics refine compiler-specific flow.', 'complete'),
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

    /**
     * @param list<Code> $diagnosticCodes
     * @param string|list<string> $fixtureIds
     */
    private function capability(
        string $id,
        string $name,
        CapabilityCategory $category,
        CapabilityRequirement $requirement,
        CompilerCoverage $compilerCoverage,
        SupplementalCoverage $supplementalCoverage,
        array $diagnosticCodes,
        string|array $fixtureIds,
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
            is_string($fixtureIds) ? [$fixtureIds] : $fixtureIds,
            $notes,
            $migrationSlice,
        );
    }
}
