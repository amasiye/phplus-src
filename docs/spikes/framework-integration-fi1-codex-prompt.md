# FI-1 Implementation Prompt — Shared Platform And Runtime Foundation

Status: repository-contained implementation handoff, not a claim that the following capabilities exist. Work from latest local `develop` in `atatusoft-ltd/ppphp-src`. Preserve concurrent work, the current release identity and accepted language RFCs. Push only `develop`, never a remote feature branch or a force push. This prompt does not authorize tags, release publication, `main` pushes or production framework adapter packages.

## Required Existing Inputs

Read completely before implementation:

- `AGENTS.md`
- `docs/ppphp-mvp-end-to-end-plan.md`
- `docs/ppphp-framework-integration-plan-amendment.md`
- `docs/spikes/framework-integration-2026.4.md`
- `docs/spikes/framework-integration-matrix.md`
- `docs/spikes/framework-integration-evidence.md`
- `docs/composer-runtime.md`, `docs/build-output.md`, `docs/mixed-projects.md`, `docs/portable-declarations.md`
- `docs/decisions/0003-content-addressed-compiler-cache.md` and `docs/decisions/0004-mvp-native-analysis-retains-phpstan.md`

Inspect the existing spike implementation and fixtures under `tests/Support/FrameworkIntegrationSpike/`, `tests/Fixtures/FrameworkIntegration/`, and `tests/Unit/Compiler/Framework*Test.php`. They are experiments, not production components to copy wholesale. All required inputs are in the same repository; no attachment or private chat is needed. Read the current implementation before trusting historical line numbers in the inventory.

## Objective And Guardrails

Deliver evidenced framework-neutral PHP 8.4/8.5 platform support and runtime layout/resource ownership without changing ++PHP semantics. AssegaiPHP and Laravel must receive first-class integrations during `2026.4.x`; those adapters belong to FI-2/FI-3. PHP 8.5 is shared FI-1 work, independent of a Tempest adapter. Preserve accepted language contracts and follow the current owner-approved Stage 15 schedule, including explicit later scheduling amendments; framework capacity must not depend on implementing those language features first.

Keep compiler host, project/dependency syntax, versioned signatures/extensions, emitted PHP syntax, and complete-application runtime separate. Retain the released host minimum unless a separate explicit decision changes it. No framework-name-to-platform switch, host-reflected signature selection, wildcard future-version support, arbitrary backward transpilation, or copied PHP rewrites. An additional schema enum value is not implementation.

Portable analysis remains non-executing and normal checks keep their supplemental backend contract. Never bootstrap application autoload/scripts, framework configuration or user PHPStan configuration during portable analysis. No global suppressions/baselines, mandatory new Composer plugin, framework-magic semantics, framework dependencies in the compiler or speculative empty interfaces.

## Bounded Slices

### A. Central Platform Selection And Rejection

Start with `src/Config/ProjectConfigLoader.php`, `src/Frontend/PhpParserAdapter.php`, `src/Frontend/Token/Lexer.php`, `src/Semantic/When/WhenFragmentParser.php`, `src/Analysis/DeclarationContextCollector.php`, and `resources/schema/ppphp.schema.json`.

Create one capability selection contract with actual consumers. Preserve the existing 8.4 default and old configuration behavior. Decide the minimal public configuration only after tracing every dimension; do not copy the spike's five strings into a public schema by assumption. Treat unknown combinations as catalog-owned diagnostics, not PHP exceptions. Verify host 8.4 can tokenize/normalize/parse actual newer native and ++PHP contexts, not merely upstream parser specimens. Keep source spans/diagnostics valid. Review property hooks, pipe and other authoritative 8.5 changes; reject unsupported lowering explicitly.

Gate: same fixtures differ correctly under 8.4/8.5, unknown profiles reject, previous diagnostics/goldens remain valid. No advertised 8.5 support yet.

### B. Dependency Parsing And Resource Bounds

Trace `src/Interop/Composer/ComposerDependencyDeclarationLoader.php`, `ComposerDependencySourceInspector.php`, `Declaration/InstalledComposerDeclarationProvider.php`, `Index/PortableDeclarationValidator.php`, `Index/DeclarationCompatibilityIdentity.php`, `src/Analysis/DeclarationContextCollector.php` and `tools/build-dependency-index.php`.

Use an explicit supported dependency syntax contract across loader, body-free validator and index identity. Preserve provenance, authority, path containment, non-execution and focused context. The validator's newest-parser choice and loader's fixed parser must not disagree silently.

Reproduce measured launch-project memory failures from the evidence report with pinned locks. Profile retained sources/tokens/ASTs and dependency closure before changing limits. Prefer bounded, relevant declaration data over retaining unnecessary full bodies. Diagnose resource failures deliberately; do not remove bounds or repeatedly raise memory ceilings to hide runaway work. Distinguish sandbox/backend execution restrictions from compiler defects.

Gate: older host/newer dependency grammar accepted where supported, incompatible/unsafe declaration data rejected specifically, actual framework context completes within a documented measured budget. No framework application is executed to obtain declarations.

### C. Versioned Built-In And Extension Knowledge

Use `src/Interop/Php/Signature/PhpSignaturePackageLoader.php`, `PhpSignaturePackageGenerator.php`, `PhpStubNormalizer.php`, `PhpSignaturePackageVerifier.php`, `resources/php-signatures/8.4/`, `tools/verify-php-signatures.php` and the signature/index tests.

Generate authoritative PHP 8.5 data with source revision, package provenance, deterministic symbols/shards/overrides and verification. Preserve 8.4 data as its own identity. The loader already selects a directory by target but retains a default parser: fix the full path, not only the directory string. Verify APIs added/changed/removed and extension requirements, including a genuine 8.5-only function. Never relabel the spike's small registry as complete signatures.

Gate: real positive/negative API analysis and deterministic package verification for both platforms on supported hosts.

### D. Emission, Explicit Interpreters And Application Requirements

Trace `src/Transpilation/Emission/ProductionPhpEmitter.php`, existing lowering passes, `src/Compiler/Validation/SymfonyPhpLintRunner.php`, `src/Analysis/PhpStan/PhpStanAnalysisPlanBuilder.php`, `PhpStanConfigBuilder.php`, and bounded process abstractions.

Validate permitted output capabilities per target; unsupported transformations reject rather than falling back. Ordinary PHP remains byte-for-byte copied, including launchers. Select lint/runtime executables explicitly and verify actual versions; never quietly use host `PHP_BINARY` for a newer target. Preserve process time/output limits and argv invocation. Handle missing/wrong executables with actionable diagnostics.

Validate the complete application, including native PHP, Composer locked constraints and required extensions. Use Composer's actual constraint/platform semantics, not the spike's lower-bound shortcut. No ignored requirements. Distinguish syntax success from API/runtime compatibility.

Gate: real emitted ++PHP output lints/runs on 8.4 and 8.5, including host 8.4 → target/runtime 8.5; mismatched native/API/dependency/extension cases fail with expected diagnostics.

### E. Evidence Identity, Manifests, Tooling And CI

Trace `src/Cache/CompilerBuildIdentity.php`, `ProjectInputSnapshotBuilder.php`, `CompilerCache.php`; `src/Compiler/Manifest/BuildManifest.php`, `BuildManifestCodec.php`, `ConfigurationFingerprint.php`; `src/Compiler/Output/AtomicBuildCommitter.php`; release/distribution verifiers and `.github/workflows/php.yml`.

Extend identity coherently for selected syntax, signatures/overrides, emitter capabilities, relevant dependency metadata and actual validation runtime/extensions. Existing target strings and hard-coded signature paths are insufficient. Version serialization, migration/rejection and conservative cache misses; keep core/supplemental evidence separate. Test persisted cross-target warm/cold/corrupt cases and manifest recovery, not only differing hashes.

CI must distinguish host coverage from full target/runtime support. Required combinations: 8.4→8.4, 8.5→8.5, 8.4→8.5, and any additional claimed combination. Each result records compiler/host/parser/signature/emission/runtime/lock/extensions/command/exit/status. Keep historical 8.4 fixture/release pins; add new evidence. Publish support combinations only after real matrix success; do not change current release identity as part of capability plumbing.

### F. One Layout/Classification Plan

Trace `src/Project/ProjectSource.php`, `ProjectLoader.php`, `SourceSet.php`; `src/Compiler/Output/OutputPathResolver.php`, `OutputPlanner.php`; `src/Interop/Composer/ComposerRuntimeConfigurator.php`; emission, source maps and editor projections.

Settle configuration alternatives from the spike: mixed string/object sources, a separate mount field, or unified classified entries. Record an ADR only after choosing with evidence; no automatic adoption of experimental names. String roots keep empty-mount behavior. Model file and directory roots explicitly, including extensionless PHP launchers and executable mode/line offsets. Reject malformed mount filenames rather than truncating arbitrary suffixes.

Use one destination/ownership calculation for emission, Composer projection, maps, editor declarations, resources and manifests. Exact rules: normalized relative forward-slash mounts; no traversal/absolute/drive escapes; existing case-folded destination policy; no source/resource ambiguity or file-directory conflicts; no `.ppphp` opaque smuggling; specific template rules only; ordinary config/language PHP stays source; resources preserve bytes/hashes. Asset inputs are not built bundles. Reuse production path safeguards and add resource bounds/TOCTOU tests before writes.

Gate: legacy outputs unchanged, full mounted fixture noncolliding, same owner map consumed everywhere, file-root Composer projection works, no duplicate IDE/runtime declarations.

### G. Resource Transactions And Persistent State

Integrate into existing operation lock, durable journal, staging/atomic replacement, manifest, source-map and clean contracts. Do not promote the in-memory spike selector into a filesystem writer.

Give copied resources and created empty directories deterministic ownership. Resource delete/rename must remove only previously owned stale items. Partial builds must not certify a complete project. Failure/interruption cannot expose partial output or invalidate a prior good generation.

Settle external writable roots versus an explicitly preserved lifecycle. Empty directory creation is not permission to erase live state. Never copy source `.env`, logs, sessions or populated caches. Explicitly test clean/rebuild/failed preparation with populated runtime state, final-path cache regeneration, source-free relocation and permissions. Symlinks/links, if required by deployment, need their own containment/ownership decision; do not inherit the prototype's rejection as a claim of link support.

### H. Lifecycle/Profile Handoff, Without Framework Magic

Expose only evidenced structured build-success/failure/generation information needed by adapters. Profile metadata must be declarative/version-validated and explicitly selected. Detection may suggest, not activate code. No arbitrary hooks in portable analysis.

Compilation → framework discovery/cache preparation → ready generation selection → process reload is an adapter orchestration sequence. Failed compilation/preparation retains a consistent previous generation. Define failure, recovery, add/rename/delete and clean semantics; test physical filesystem behavior and actual worker reload in the adapter gates.

Keep standalone recovery available before successful application bootstrap. Do not change source Composer mappings until first build succeeds. Root proxies must eventually preserve arguments, exit codes, streams, signals and containers; generators must write authored source after runtime projection. Native entrypoint/vendor/resource paths must work without undocumented PHP rewriting. Reversible uninstall restores only recorded owned changes and refuses conflicts.

## Final Gates And Handoff

Run required repository checks (`composer validate --strict`, `composer verify:version`, `composer analyse`, `composer test`), mixed/distribution verifiers and the expanded platform matrix. Diagnose environment restrictions without weakening tests. Report FAIL/NOT RUN independently from expected-rejection PASS. Update inventory/PV table, selected ADRs, schema/docs only for actually implemented behavior and verified combinations.

FI-2/FI-3 source-free framework gates remain separate from shared platform support: creation/adoption, representative valid generators, HTTP/console/tests/migrations/queues, optimized/authoritative autoload, discovery/cache preparation, missing-output recovery, source mapping, production dependencies/resources/writable state, upgrades/removal. Do not claim these from a framework-neutral fixture. Tempest's full route/DI/event/console/view/config/migration fixture remains conditional adapter work, not a PHP 8.5 deferral.

Commit each verified bounded slice to local `develop` and push `develop` normally when authorized by the active implementation task. Preserve concurrent remote work. Verify every successor input/link exists in the repository before calling the handoff ready; never depend on an uncommitted temp installation, this conversation or attached files.
