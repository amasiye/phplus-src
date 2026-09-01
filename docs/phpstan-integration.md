# PHPStan Integration

> **Status:** Implemented in Stage 6, separated from compiler-owned project analysis in Stage 13A, and narrowed to Optional supplemental capabilities by Stage 13C. It remains mandatory for normal native check/build.

PHPStan has two independent roles:

1. `phpstan.neon.dist` checks the compiler implementation.
2. `resources/phpstan/ppphp.neon` is the compiler-owned base for user-project analysis.

PHPStan is a pinned, replaceable backend. Its rule level and wording are not the ++PHP language contract.

Stages 13A–13C measure the second role through the [capability catalog](analyzer-capabilities.md) and deterministic differential corpus. PHPStan is an oracle for broader PHP behavior, not the specification; disagreements can be compiler gaps, backend gaps, language-policy differences, supplemental analysis, optional lint, or fixture errors.

## Compiler And Supplemental Phases

`CompilerProjectAnalyzer` produces selected parses, safe declaration context, semantic models, processed diagnostics, and explicit `compilerCore` completeness without PHPStan or an analysis workspace. `ProjectChecker` reuses that result and starts `AnalysisWorkspacePreparer` only for the full native path. Selected sources are not reparsed or semantically reanalyzed during supplemental preparation.

`PhpStanProjectAnalyzer` is instantiated lazily after compiler-owned success. Thus browser protocol version 2 can use the same compiler semantics without constructing PHPStan, while ordinary `check` and `build` retain the existing full guarantees. Catalog version 3 has no required compiler gaps and reports `fullParity: true`; the compiler-only result is still not exposed as a public CLI/configuration mode.

The compiler owns syntax, project discovery and selection, symbols, declaration completeness, typed bindings and arrays, generic structure and erasure, checked-error effects, supported expression flow, known call/member/property contracts, return completeness, ordinary-PHP, configured-stub, installed-dependency, and target-PHP declaration boundaries, reviewed intrinsics, `when` semantics, diagnostic codes, source mapping, and production output. PHPStan supplements generator-specific flow, deep ordinary-PHP bodies, and optional lint; it never decides which ++PHP feature is valid or how source is emitted.

## Analysis Workspace

Each check prepares `.ppphp-cache/analysis/` with deterministic areas for selected files, unselected context, configured stubs, backend configuration, mappings, results, and temporary backend data. A source-root hash prevents equal relative paths from different roots from colliding.

Selected `.ppphp` is parsed, checked, and lowered to analysis PHP with complete generic, typed-array, composite, checked-error, and `when` result metadata. Selected `.php` is copied byte-for-byte. Valid unselected sources contribute declaration-only context: namespaces, imports, constants, functions, class-like headers, members, native types, generic metadata, and checked-error contracts are retained while executable bodies are replaced. An unrelated body error therefore cannot block a focused command.

An unselected declaration whose own header or generic contract is invalid is omitted rather than fabricated into apparently valid backend context. A focused source that depends on that declaration still reports the unresolved dependency at the selected source; an independent focused source remains isolated from the unrelated failure. Configured stubs are supplied as `stubFiles` and scan context. Preserved Composer source PSR-4, classmap, and files paths are scanned as data even after runtime mappings point to generated output.

Production lowering returns generated contents, the source edits, and a generated-to-original source map. Copied PHP uses an identity map. Backend findings are mapped through these records to original `.ppphp` or `.php` spans. Successful production builds persist the same mapping model beneath the output `.ppphp/source-maps/` directory; analysis remains independent of a prior production build.

For `when`, the analysis workspace receives real closure-free conditional control flow rather than the parser's normalized placeholder. Result temporaries carry the inferred semantic type at their consuming statement. Source-edit submappings associate conditions, result expressions, and temporary uses with the owning source spans, so backend member, argument, return, and typed-array findings report the original `.ppphp` location without generated variable names or synthetic control-flow paths.

## Process Boundary

`PhpStanProjectAnalyzer` implements the compiler-owned `ProjectAnalyzer` interface. It resolves the Composer-installed PHPStan binary under the compiler package and invokes it through `PHP_BINARY` and Symfony Process with argument-array execution, JSON output, no progress display, a finite timeout, and a generated configuration.

Exit code 0 is success and exit code 1 may contain normal source findings. A backend invocation must still return the complete JSON envelope; an empty response is not success. Timeouts, missing executables, unexpected exits, empty or malformed JSON, and invalid result shapes become `P6005` or `P6006` diagnostics.

The generated configuration sets the target PHP version, selected `paths`, context `scanFiles` and `scanDirectories`, configured `stubFiles`, and a workspace-local `tmpDir`. The PHPStan level is compiler-owned and is not a project setting.

## Checked Exceptions

The compiler-owned base configuration treats Exception descendants as checked and Error descendants as unchecked. Implicit throws are disabled; missing checked `@throws` declarations and throw-contract covariance are enabled. Unused throws findings are suppressed because a native ++PHP clause is an explicit public contract.

Supported backend exception identifiers map to P4002, P4003, P4004, P4012, or P4013. Internal semantic diagnostics run first, and project checking stops before backend analysis when those errors already invalidate the selected source. Remaining findings carry an explicit backend origin and identity, are rewritten to compiler-oriented messages and original source spans, and pass through the same bounded suppression and deterministic sorting pipeline as compiler-owned findings.

## Generics And Typed Arrays

The compiler emits PHPStan-compatible `@template`, dependent and applied bounds, parameter, return, property, inheritance, trait-use, anonymous-callback, `list<T>`, and `array<K, V>` metadata before backend analysis. Compiler-owned member resolution and owner-qualified substitution establish project-known types first; PHPStan completes broader flow-sensitive call-site analysis. Stable compiler mappings convert supported generic and collection findings to P3xxx diagnostics.

Ordinary PHP and configured stubs are parsed through the same PHPDoc model and may provide generic contracts to ++PHP callers. Native ++PHP generic syntax is authoritative over PHPDoc on the same declaration. Raw generic types remain permitted at ordinary PHP boundaries when their PHPDoc contract does not provide arguments, but are rejected in .ppphp.

Stage 11 verifies these contracts through ordinary-PHP generic interfaces implemented by ++PHP, generated generic classes consumed by PHP, stub-supplied checked errors, list and map metadata, nullable/chained receivers, and focused checks that retain valid cross-language context without surfacing unrelated invalid files.

## Security Boundary

Analysis does not load or execute:

- a project `phpstan.neon`, baseline, bootstrap, extension, or custom rule;
- project `vendor/autoload.php`;
- Composer scripts or plugins;
- Composer `autoload.files` entries as executable code; or
- application bootstrap files.

Source and metadata are parsed or scanned as data. Normal diagnostics never expose analysis-cache paths, backend command lines, generated analysis variables, temporary configuration paths, or raw backend identifiers. `--debug` retains normalized implementation details and the explicit diagnostic origin for infrastructure diagnosis.

## Dependency Direction

`src/Semantic` and the compiler-owned browser protocol do not depend on `Analysis\PhpStan`, Symfony Process, `AnalysisProject`, or continuation state. The PHPStan adapter may depend on compiler-owned models, lowering, source maps, and diagnostics, but those core modules may not depend back on the adapter.

Dependency placement is unchanged in Stages 13A–13C. `phpstan/phpstan` remains required by the full native path and compiler-development analysis. `phpstan/phpdoc-parser` remains a direct compiler PHPDoc dependency. Symfony Process also remains required for production `php -l`, so backend optionalization alone would not make all compiler operations process-free.

Architecture tests enforce that compiler-core files do not import backend or Process namespaces, and packaging tests record the current runtime/development split. A future optional backend package or installation profile must preserve the native default, structured missing-backend behavior, distribution contents, and upgrade contract. Stage 13C proves that all required declaration boundaries are portable; it does not silently change those product guarantees.
