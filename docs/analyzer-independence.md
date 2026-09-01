# Analyzer Independence

> **Status:** Stage 13A implemented; native full analysis remains the default.
> **Evidence:** capability catalog version 1, 33 differential scenarios, 22 Complete, 8 Partial, 3 Backend-only, and 10 required compiler-only gaps.

## 1. Executive Summary

Stage 13A separates a useful compiler-owned project analysis from PHPStan preparation and execution. `CompilerProjectAnalyzer` parses the selected source set, collects safe declarations from unselected project sources, runs semantic analysis once, processes stable diagnostics, and returns `CompilerProjectAnalysis`. The result is usable without an `AnalysisProject`, generated PHP, a PHPStan configuration, an executable, a result handoff, or a child process.

This is an architectural foundation, not a PHPStan removal. Normal native `ppphp check` and `ppphp build` still add the supplemental PHPStan phase. Browser protocol version 2 exposes only the bounded compiler core and returns `completeness: compilerCore`, `fullParity: false`, and the exact catalog-derived required gaps. No public compiler-only command or configuration mode exists.

## 2. Current Architecture

The implemented flow is:

```text
ProjectConfigLoader -> ProjectLoader -> ProjectSelector
                                      |
                                      v
                            CompilerProjectAnalyzer
                            - selected syntax
                            - declaration context
                            - semantic analysis
                            - stable diagnostics
                                      |
                   +------------------+------------------+
                   |                                     |
          Browser protocol v2                   native ProjectChecker
          compilerCore response                 supplemental preparation
                                                        |
                                                        v
                                              PhpStanProjectAnalyzer
                                                        |
                                                        v
                                              full ProjectCheckResult
```

`DeclarationContextCollector` owns focused declaration safety. `AnalysisWorkspacePreparer` now consumes a successful compiler result; it no longer discovers the selected syntax or causes selected semantic analysis to run again. `SupplementalAnalysisPreparation` explicitly binds the compiler result to an optional prepared backend project. PHPStan construction is lazy and occurs only on the supplemental path.

## 3. Current PHPStan Responsibilities

PHPStan has two independent roles:

1. Compiler development analysis: the Composer `analyse` script uses `phpstan.neon.dist` to check the compiler implementation. This role is development tooling.
2. User-project supplemental analysis: the normal native check/build path lowers selected ++PHP, supplies declaration-only context, configured stubs, Composer/vendor scan context, and a generated compiler-owned PHPStan configuration. This role contributes user diagnostics.

For user projects, PHPStan currently supplies complete general argument and return validation, all-path return checks, broad member/symbol existence, property and nullability flow, mature built-in signatures, external Composer/vendor inference, and deep ordinary-PHP body analysis. It also contributes fallbacks for some generic, collection, and checked-error findings. Backend failure parsing maps to `P6005` and `P6006`.

PHPStan does not define ++PHP syntax, generic identity, type-parameter substitution, checked-error policy, `when` behavior, diagnostic codes, source mapping, lowering, or build output.

## 4. Compiler-Owned Responsibilities

The compiler owns PHP and extension parsing; project discovery and selection; strict ++PHP declaration rules; typed local, loop, property, and collection rules; symbol and resolved-name tables; composite types; generic declarations, arity, bounds, substitution, invariance, dependent bounds, and applied `$this`; checked-error declarations, propagation, catches, covariance, and dynamic boundaries; `when` control/value flow; diagnostic identity and presentation; source maps; lowering; and atomic builds.

Stage 13A adds ownership of the project-analysis result, completeness metadata, the capability catalog, the differential corpus, and the compiler-only browser protocol. The compiler result is real semantic work, not a no-op analyzer.

## 5. Browser/PHP-WASM Evidence

The Stage 13A spike packages the real repository compiler and Composer dependencies, verifies the archive hash, extracts it into the browser filesystem, and runs it with PHP 8.4.23 WASM in real Chromium. One version 2 top-level compiler invocation analyzed a two-source virtual project: a valid generic/`when` source remained clean and an invalid typed initializer produced only `P2008` against `src/invalid.ppphp`.

The response reported catalog version 1, `compilerCore`, `fullParity: false`, and all 10 required gaps. No spawn handler was installed, no PHPStan plan or continuation was returned, no analysis workspace was created, and `_getcontext` was not entered. The separate version 1/top-level PHPStan experiment was then run unchanged and reproduced the expected `_getcontext` abort. Disposable-worker termination still contained runaway code. These observations prove portable compiler-core checking, not browser full analysis, building, preview compilation, or user-code execution.

## 6. Ordinary PHP Policy Alternatives

Model A, full mixed-body parity, would require the compiler to reproduce all release-required deep flow analysis for ordinary PHP immediately. It offers one simple guarantee but expands the analyzer substantially and risks rebuilding optional PHP lint.

Model B, strict ++PHP with contract-oriented PHP, makes the compiler authoritative for all ++PHP bodies and for ordinary-PHP declarations, PHPDoc contracts, stubs, and calls that cross the boundary. Deep ordinary-PHP body analysis can remain supplemental where it does not affect ++PHP correctness.

Model C, native full plus portable core, exposes the same distinction operationally: native full analysis remains mandatory while the portable compiler core grows under differential testing. It is a migration model rather than the final language policy.

## 7. Recommended Target Contract

Adopt Model B as the target contract and Model C as the rollout mechanism. Compiler-owned analysis must become Complete for strict ++PHP and for every ordinary-PHP declaration or effect visible across a ++PHP boundary. Deep ordinary-PHP bodies may remain supplemental until their absence can cause a required false negative; those cases then become compiler requirements in the catalog.

The native full path remains authoritative during migration. `compilerCore` is explicitly incomplete while any Mvp or Boundary capability is Partial or Backend-only. Optional lint differences do not block eventual promotion unless separately approved as product requirements.

## 8. Capability Matrix

The typed catalog and its generated table live in [Analyzer Capabilities](analyzer-capabilities.md). Version 1 contains 33 capabilities: 22 Complete, 8 Partial, and 3 Backend-only. The 10 required gaps are:

- `calls.arguments`, `calls.builtins`, `calls.members`, and `calls.returns`;
- `flow.properties`;
- `types.aliases` and `types.assignability`; and
- `interop.ordinary-php`, `interop.stubs`, and `interop.composer-vendor`.

Every Complete or Partial entry cites an executable fixture. Catalog ordering, unique IDs, diagnostic-code validity, evidence existence, documentation parity, and catalog-version stability are tested.

## 9. Target Analyzer Architecture

The target extends the implemented compiler core without replacing it wholesale:

- Binding: scopes, declarations, captures, definite initialization, and readonly writes.
- Control flow: basic blocks for branches, loops, return, throw, try/catch/finally, termination, and unreachable flow.
- Flow facts: null checks, `isset`, `instanceof`, deliberately supported truthiness, union narrowing, and assignment updates.
- Expression typing: literals, operators, calls, members, arrays, closures, `when`, `match`, `new`, and nullsafe access.
- Call resolution: functions, methods, constructors, named arguments, defaults, variadics, references, and generic inference.
- Object model: inheritance, interfaces, traits, visibility, overrides, property hooks, and asymmetric visibility.
- Type system: native types, unions, intersections, DNF, generics, typed arrays, type parameters, substitution, `never`, `mixed`, and `null`.
- Interop: PHPDoc, stubs, Composer metadata, ordinary-PHP declarations, and built-ins.
- Effects: checked errors and dynamic boundaries.
- Diagnostics: stable compiler codes and original source spans.
- Incrementality: file/declaration dependencies, invalidation, and reusable analysis facts.

Stage 13A implements the orchestration boundary and evidence model only; it does not implement this entire target.

## 10. Control-Flow Graph Requirements

A future control-flow graph must represent callable entry/exit, normal and exceptional edges, short-circuit conditions, loops, `break`/`continue`, `return`, `throw`, `try`/`catch`/`finally`, terminating expressions, `match`, and lowered-equivalent `when` branches. Nodes must retain original AST identity and source spans. Finally edges must correctly replace or preserve pending return/throw outcomes.

The graph must be deterministic, side-effect free, and reusable by definite initialization, all-path returns, narrowing, unreachable-flow diagnostics, and checked-error propagation. Stage 13B should build only the pieces needed by measured gaps rather than a speculative general framework.

## 11. Data-Flow And Narrowing Requirements

Flow facts must be immutable or versioned per basic-block edge and join through explicit lattice operations. Required facts include local/property initialization, assigned types, null elimination, `isset`, `instanceof`, supported truthiness, and union-arm elimination. Mutation, by-reference calls, unknown calls, dynamic property/member access, and escapes must conservatively invalidate affected facts.

The analyzer must prefer an explicit unknown result to unsound inference. It must not use PHPStan output as internal state. Diagnostic decisions must remain reproducible from compiler-owned facts.

## 12. Call And Member-Resolution Requirements

Build on `SourceTypeResolver` and `MemberTypeResolver`. Resolution must cover project functions, methods, constructors, inherited/interface/trait members, visibility, property hooks, static versus instance access, named arguments, defaults, variadics, by-reference parameters, nullsafe receivers, and generic inference/substitution. Every reachable union or intersection arm must satisfy the selected operation according to the language contract.

External unresolved symbols must cross an explicit boundary: compiler stubs or a portable dependency index when available, otherwise a conservative incomplete capability. Dynamic calls remain `P4005` boundaries rather than being fabricated as statically known.

## 13. PHPDoc And Stub Requirements

The existing PHPDoc parser may continue to normalize `@template`, `@param`, `@return`, `@var`, inheritance, trait-use, and `@throws` contracts. Native ++PHP syntax remains authoritative. Ordinary PHP and configured stubs contribute declarations as data and are never executed.

Portable analysis needs deterministic symbol records for PHPDoc aliases, templates, shapes needed by release fixtures, and stub precedence. Malformed or unsupported contracts must fail closed with stable compiler diagnostics or explicit incomplete coverage. Large third-party stub corpora are not copied into this repository during Stage 13A.

## 14. Built-In Signature Strategy

Five approaches were evaluated:

- Hand-maintained compiler stubs are precise and reviewable but costly and easy to leave incomplete.
- PHP reflection generation tracks the current runtime but is nondeterministic across hosts and cannot describe unavailable browser extensions.
- Official PHP metadata generation is deterministic after pinning but needs a maintained importer and semantic normalization.
- Reusing PHPStan stubs is broad but ties the portable core to backend packaging and upstream representation choices.
- A versioned hybrid uses generated official metadata for the configured PHP target plus small reviewed compiler overrides for ++PHP-relevant behavior.

Use the versioned hybrid as the target. Generated data must be checked in or reproducibly generated, keyed to the configured PHP target, normalized into compiler-owned types, and covered by fixtures. Runtime reflection may verify development output but cannot be the browser source of truth.

## 15. Dependency/Package Strategy

Stage 13A changes no dependency placement. `phpstan/phpstan` remains a runtime dependency because normal check/build use it. `phpstan/phpdoc-parser` is directly used by compiler-owned PHPDoc parsing and may remain even after backend optionalization. `symfony/process` remains required both for the native PHPStan adapter and for production `php -l`; process-free analysis does not imply process-free production linting. `nikic/php-parser` and `symfony/console` remain core compiler dependencies.

After promotion, first make the user-project PHPStan adapter optional behind an explicitly installed package or development/shadow configuration while retaining compiler development analysis. Move or remove `phpstan/phpstan` only after native behavior, packaging errors, and documentation are designed and tested. Do not add another analyzer dependency.

## 16. Differential-Testing Strategy

`tests/Fixtures/AnalyzerParity/scenarios.php` declares stable scenario and capability IDs, virtual project sources, selection, expected compiler diagnostics, expected full diagnostics, release-blocking status, and expected disagreement class. `AnalyzerParityRunner` runs the compiler-owned analyzer and the ordinary full checker independently, compares code, original path, half-open byte range, and stable semantic identity, and never records raw backend wording or temporary paths.

Disagreements are classified as `CompilerGap`, `BackendGap`, `LanguagePolicyDifference`, `OptionalLint`, or `FixtureError`. The deterministic report contains no timestamp, PID, absolute path, or duration. `composer verify:analyzer-parity` compares it with the reviewed golden; `UPDATE_ANALYZER_PARITY=1` is the only update path. PHPStan is an oracle that can itself be wrong or optional, not the specification.

## 17. Browser Protocol Strategy

Version 1 Prepare Analysis remains compatible and process-oriented. It can prepare Check or Build analysis, materialize the PHPStan workspace, and return a content-addressed continuation and top-level PHPStan command.

Version 2 is a one-shot `analyze` action with `operation: check` and `analysis.engine: compiler`. It invokes the compiler core once and returns normal Stage 12 diagnostics plus compiler identity, catalog version, `compilerCore` completeness, `fullParity`, and uncovered required capabilities. It cannot build, return a continuation, return a command, or invoke a supplemental analyzer. No public human-facing compiler-only mode is exposed in Stage 13A.

## 18. Security And Resource Limits

Version 2 accepts at most 16 KiB per request, 256 project source/stub files, 4 MiB total source bytes, 1,000 diagnostics, and a 2 MiB response. Limit failures return a complete structured `resource-limit-exceeded` error; JSON is never truncated. Request files must be regular files contained by the project root.

Compiler-only analysis does not execute user source, project autoload entrypoints, Composer scripts/plugins, application bootstraps, PHPStan configuration, or arbitrary bootstrap files. It performs no lowering or output writes and creates no backend workspace. Browser cancellation terminates the disposable worker; no timing delay is used as a synchronization workaround.

## 19. Incremental-Analysis Strategy

Incrementality is not implemented in Stage 13A. The target cache key combines compiler and catalog version, target PHP version, normalized project configuration, source/stub contents, relevant Composer metadata, and analysis mode. Cache entries should separate parsed syntax, declarations, symbol indexes, semantic facts, and supplemental results so compiler-core reuse does not depend on PHPStan.

The dependency graph should invalidate consumers by declaration identity and body facts, not merely timestamps. Corruption must produce a safe miss. Persistent records need versioned schemas, project-relative identities, bounded sizes, and atomic writes under `.ppphp-cache`. Browser callers may begin with in-memory reuse before persistent virtual-filesystem caching.

## 20. Migration Phases

Stage 13A is complete foundation work: separation, completeness, catalog, parity baseline, version 2, real-WASM evidence, and documentation.

Stage 13B is next and is selected from nine measured core/type-flow gaps: argument, return, member, and built-in validation; property/general assignability flow; external type aliases; ordinary-PHP boundary contracts; and stub symbols. It should add the smallest compiler-owned CFG/data-flow and signature index needed to close those fixtures.

Stage 13C should address portable Composer/vendor indexing and the deterministic built-in signature package, then decide whether all Boundary capabilities can be Complete or require approved conservative boundaries.

Stage 13D should implement measured incrementality, cache security, performance characterization, malformed-input hardening, and supported protocol cleanup. Stage 14 remains the MVP release stage; its numbering and release contract are preserved.

## 21. Promotion Gates

Compiler-owned analysis may replace PHPStan as the native default only when:

- every Mvp capability is Complete;
- every Boundary capability is Complete or has an approved conservative boundary;
- no backend-only blocking finding or known required false negative remains;
- canonical examples have no known compiler false positive;
- mixed-application and shopping-cart verification pass without supplemental analysis;
- generated output still passes `php -l`;
- native full and compiler-only diagnostics agree on every required parity fixture;
- one-process browser analysis, resource limits, and cancellation remain verified; and
- a separate decision explicitly approves the switch.

Optional lint parity is not a promotion gate unless product policy changes.

## 22. Rollback Strategy

The default remains the established full path, so Stage 13A needs no user-facing analyzer rollback switch. If compiler-only protocol behavior regresses, disable or remove version 2 while retaining version 1 and native checks; do not weaken normal diagnostics. If a catalog claim is wrong, downgrade it, add a reproducing fixture, update the golden explicitly, and select the gap for migration.

Each future promotion must be reversible at the orchestration boundary: keep compiler and supplemental results distinct, retain the full parity corpus, and avoid storing cache data that only one analyzer can interpret without versioning.

## 23. Known Risks

The compiler still lacks broad PHP expression flow, return-path coverage, comprehensive built-in signatures, and a portable vendor index. Ordinary-PHP boundary requirements can grow as real projects expose new contracts. PHPStan and compiler results can disagree because of backend defects, optional lint, source lowering, or language policy. A catalog can become stale unless fixtures and docs remain mechanically verified.

PHP-WASM payload size and Content Security Policy remain production concerns. The browser gate proves checking only; PHPStan still aborts at `_getcontext`, browser production build is unsupported, and no user code may run in the analyzer worker.

## 24. Explicit Non-Goals

Stage 13A does not replace or remove PHPStan; move it to `require-dev`; add another analyzer; implement a complete CFG or full narrowing; provide full ordinary-PHP body parity; expose public compiler-only check/build modes; implement compiler-only build; couple in process to undocumented PHPStan APIs; copy a large third-party stub corpus; execute user code; implement browser production builds; add language features, generic variance, native scalar members, formatter support, a standalone LSP, release automation, or a timing/partial-JSON workaround.
