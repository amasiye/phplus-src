# Codex Prompt — ++PHP FI-0 Framework Integration Evidence Spike

> Repository-local replacement for the attachment-only FI-0 and alternate Stage 17A handoffs.
> Programme: FI-0 through FI-4. Required `2026.4.x` integrations: AssegaiPHP and Laravel.
> Status: Instructions to execute, not evidence that the spike or production support has already been implemented.
> Updated: 2026-09-05.

Work in `atatusoft-ltd/ppphp-src` from the latest local `develop`. This prompt contains the full experimental work order. Its planning companion is [the committed programme amendment](ppphp-framework-integration-plan-amendment.md). No chat attachments, private conversation, supplied ZIP, separate Stage 17A prompt or unpublished document is required.

## 1. Read The Repository Before Changing It

Required existing inputs, relative to repository root:

```text
AGENTS.md
docs/ppphp-mvp-end-to-end-plan.md
docs/ppphp-framework-integration-plan-amendment.md
docs/composer-runtime.md
docs/build-output.md
docs/mixed-projects.md
docs/portable-declarations.md
src/Config/ProjectConfigLoader.php
src/Compiler/Output/OutputPlanner.php
src/Compiler/Output/OutputPathResolver.php
src/Transpilation/Emission/ProductionPhpEmitter.php
src/Interop/Composer/
```

Inspect relevant existing tests for configuration, output planning, Composer projection, manifests, partial builds, source-free deployment and mixed applications. Inspect current accepted RFCs and repository conventions. Read implementation status from actual `develop`, not the historical release/status text in an attachment.

The companion amendment is authoritative for the post-MVP FI programme. Preserve accepted language contracts and follow the current owner-approved Stage 15 schedule in the canonical plan and RFC index. FI-0 does not independently renumber language work; explicit owner-approved changes to unimplemented stages take precedence over historical schedules and must be reconciled across the planning documents. Do not change the current release identity, publish a release, or claim MVP publication complete as part of FI-0.

All `docs/spikes/` paths and the new fixture directory listed below are deliverables to create or update, not missing prerequisites. Confirm prerequisite paths exist before implementation; if the repository has legitimately moved one, locate its current equivalent and record that mapping instead of requiring chat context.

External research inputs are current official framework documentation and package/source repositories. The programme amendment supplies research entry points. Inspect `assegaiphp/core` and `assegaiphp/console` read-only. External access is not assumed: record exact failures and leave affected evidence NOT RUN; do not invent results. Do not modify other framework repositories during FI-0.

## 2. Product Objective And Boundaries

The required `2026.4.x` outcomes are first-class native AssegaiPHP integration and first-class official Laravel integration. First-class covers creation/adoption, valid generators, mixed source, development/watch, tests, HTTP/console, migrations/queues, production build, source-free deployment, source diagnostics, recovery, version coverage and removal. A framework command wrapping `ppphp build` alone is insufficient.

The wider research set is AssegaiPHP, Laravel, Symfony, CakePHP, Tempest, Yii 2, Yii 3, Drupal, WordPress and Joomla. Yii 2 and Yii 3 are separate targets. Tempest is an early architectural probe, not a third launch integration. Its platform requirements inform the shared foundation immediately.

Framework integration uses:

```text
FI-0 — Framework Integration Evidence Spike
FI-1 — Multi-Version Platform, Runtime Layout And Resource Pipeline
FI-2 — AssegaiPHP Native Integration
FI-3 — Laravel Official Integration
FI-4 — Framework Queue Validation
```

Keep ++PHP semantics framework-neutral. Normal `.php` remains byte-for-byte copied; `.ppphp` is compiled to ordinary PHP. Portable analysis does not execute user source, Composer scripts, project autoload entrypoints or framework bootstrap. Normal analysis remains on its current supplemental path unless a separate approved decision changes it. No framework-magic emulation, mandatory Composer plugin or speculative public adapter API is introduced by this spike.

FI-0 preserves current production command behavior, public schema, release identity and compiler-host minimum. This explicitly permits and requires internal/test-only multi-version experiments, a runnable evidence harness and isolated PHP 8.5 test environments. It does not defer production PHP 8.5 work to a Tempest-specific backlog: FI-1 owns that shared work.

## 3. Shared Multi-Version Platform Experiment

The initial PHP baseline is not the architectural ceiling. Model separately:

1. Compiler host: interpreters and tooling dependencies that execute the compiler.
2. Project/dependency syntax: source grammar the parser and declaration loader understand.
3. Platform knowledge: versioned built-in signatures and relevant extension capabilities.
4. Emission target: syntax/capabilities permitted in generated PHP.
5. Complete-application runtime: requirements of generated PHP, copied native PHP and dependencies together.

Do not equate emitted PHP 8.4-compatible syntax with PHP 8.5 dependency-analysis support. Do not automatically raise the compiler-host minimum when supporting a newer project platform. Do not promise arbitrary backward transpilation or mutate native PHP to disguise incompatibility.

### 3.1 Inventory Actual Assumptions

Find version assumptions in schema/configuration, parser setup, built-in signature packages, portable indexes, Composer dependency parsing, lowering, lint runners, cache keys, manifests, tooling dependencies and CI. Report exact files/symbols and distinguish host constraints, platform capabilities, legitimate historical fixture pins and accidental restrictions. No blanket replacement of `8.4` with `8.5`.

### 3.2 Prototype The Shared Model

Implement an internal/test-only capability representation exercising 8.4 and 8.5 as the first concrete pair, not a permanently two-version design. Profile selection must not switch on framework names. Do not merely widen a schema enum or add unused production interfaces.

### 3.3 Execute PV-1 Through PV-6

- **PV-1 — Version-sensitive analysis:** run the same project under 8.4 and 8.5 profiles with genuine version-sensitive syntax/API fixtures chosen from authoritative PHP sources. Correct accept/reject outcomes must differ where the language/API differs. Record what the prototype actually evaluates; a parser pass is not a compiler/runtime pass.
- **PV-2 — Host/platform separation:** run same-version combinations and a declared older-host/newer-platform combination, including host 8.4/platform 8.5 where dependencies permit. Record a blocked combination as a concrete foundation task; do not hide it by increasing the host minimum.
- **PV-3 — Correct validation/runtime:** select lint and execution interpreters explicitly, verify their actual identities, and run each claimed runtime combination. Never assume `PHP_BINARY` is suitable for a newer target. Mocked interpreter selection is unit evidence, not real compatibility evidence.
- **PV-4 — Dependency requirements:** test incompatible native-PHP, Composer/runtime and required-extension combinations and unknown targets. Expect actual diagnostics. Never use `--ignore-platform-reqs` to produce a green compatibility row.
- **PV-5 — Evidence isolation:** prove relevant target/signature/dependency/emitter changes prevent reuse of incompatible cached analysis/build evidence. Propose the required manifest identity without changing production format in FI-0.
- **PV-6 — Extensibility:** demonstrate centralized capability lookup and a reviewed path to later PHP releases. Unknown-version fixtures can prove rejection/registry behavior, not real support for an unreleased interpreter.

Add a runnable matrix harness. Discover or provision PHP executables in an isolated environment where practical. If an executable is unavailable, report the attempted command/setup and NOT RUN. Continue useful hermetic work; do not delete the missing platform task or declare complete support.

Each row must record:

```text
compiler revision
compiler-host interpreter identity
parser capabilities
signature-package identity
emission target
application-runtime interpreter identity
dependency-lock identity
relevant extensions
exact command
exit status
PASS / FAIL / NOT RUN
```

Expected rejection passes only when the expected diagnostic is observed. A missing environment is NOT RUN, not PASS. Host-only tests do not establish cross-version compatibility.

### 3.4 Handoff To FI-1

Map actual inventory findings and failed/unrun cells to bounded production changes: centralized selection, dependency-syntax parsing, versioned signatures, target-aware lowering, explicit interpreters, dependency/extension validation, cache/manifest identity and CI. PHP 8.5 belongs to the shared `2026.4.x` foundation regardless of the Tempest adapter schedule.

Describe how the next PHP release is added: authoritative change review, necessary compiler changes, verified signature generation, positive/negative fixtures, actual matrix execution and only then support publication. Unknown future versions remain unsupported until tested.

## 4. Maintainer Documents And Canonical Plan

Create or update these output documents:

```text
docs/spikes/framework-integration-2026.4.md
docs/spikes/framework-integration-matrix.md
docs/spikes/framework-integration-evidence.md
```

The first records the first-class definition, two launch gates, shared platform work, layout/resource requirements, bootstrap/recovery, analysis separation, provisional release slots, FI identifiers, preserved language work and candidate queue. Use this prompt and the committed amendment as the complete starting contract, then reconcile claims with inspected code and executed evidence.

Update `docs/ppphp-mvp-end-to-end-plan.md` with a concise, linked `2026.4 Framework Integration Programme` section, or reconcile it if already present. Link to `ppphp-framework-integration-plan-amendment.md`; record FI-0 through FI-4, the two launch gates, PV-1 through PV-6 and PHP 8.5 as shared foundation work. Do not duplicate or conflict with accepted language stages.

Retain the MVP boundary while clarifying that official post-MVP adapters can integrate creation, generation, build/watch/test/deployment, declarative metadata, safe stubs and framework-native commands without changing language semantics. No internal-stage terminology goes into public release notes, README, marketplace copy or package metadata as a claim of shipped functionality.

## 5. Framework Capability Matrix

Use separate rows for all ten named candidates. For every candidate record:

```text
exact framework/package version and revision inspected
inspection date and evidence source
compiler host, parser support, signature target, emission target, application runtime
project creation and adoption mechanism
extension/package seam
normal console command
code generator seam and destination behavior
source roots and file entrypoints
runtime/template/resource roots
writable/cache roots
Composer mapping shape
reflection/discovery and cache lifecycle
long-lived process/reload concerns
portable-analysis concerns and enhanced-analysis options
likely adapter package shape
maintenance cost and implementation difficulty
recommended implementation position and confidence
```

Verify current versions from official primary sources when running the spike. Do not use recalled versions as evidence. Keep research findings distinct from installation, analysis, lint and runtime results.

Initial priority policy: AssegaiPHP and Laravel are required; Symfony and CakePHP follow; Tempest receives early research and a conditional later adapter slot; Yii generations are scored separately. The CMS track is Drupal, WordPress and Joomla. Investigate WordPress packaging early even if its stable adapter comes later.

CakePHP: inspect plugin hooks, `bin/cake`, Bake themes/templates/events/commands, templates and ORM contracts. Yii 2: basic/advanced layouts, root `yii`, aliases and Gii. Yii 3: modular composition and official application templates, independently of Yii 2. Drupal, WordPress and Joomla initial scopes are custom modules/themes/plugins/extensions, not rewriting their cores.

## 6. Tempest Research And Minimal Probe

Inspect current official documentation, source and exact package metadata. The prior planning discussion identified a newer-PHP requirement; recheck the resolved release rather than treating a remembered version as an install matrix. Shared platform probes in section 3 remain mandatory regardless of whether a real Tempest application can be installed.

Record:

1. All five compatibility dimensions; no silent host increase or bypassed Composer requirements.
2. Composer-based reflective discovery, explicit locations, initializers and package installers. Do not invent Laravel-style service providers.
3. Discovery of generated/copied application PHP plus intended vendor code only; exclude `.ppphp` evaluation, duplicate source/output classes, tests, stale output and staging paths. Preserve concrete attributes/signatures without reified generics.
4. Co-located `.view.php`, relative view lookup and ordinary `.config.php`. Tempest owns template compilation; ++PHP syntax inside templates is out of scope.
5. Build-before-discovery order; edit/add/rename/delete invalidation; first build, missing-output and failed-discovery recovery. If compilation succeeds but discovery fails, do not reload mismatched code/cache state.
6. Writable caches must not be copied from source or lost in output replacement. Investigate external cache/lifecycle policy and final-path validity after relocation.
7. Source-aware installers/generators despite projected runtime paths. Inspect parser/import tooling before claiming it accepts `.ppphp` templates.
8. Application-frame source mapping separately from compiled-view mapping. Do not claim debugger breakpoint support without evidence.

Required Tempest-specific scope: source/documentation research, the dependency-free co-location tests below, and a later full fixture plan containing route, DI service, event handler, console command, view, configuration and migration. A real Tempest installation is optional after the two primary probes. No Tempest compiler dependency or third launch gate is added.

## 7. Characterize The Actual Layout Limitation

Use real `ProjectSource`, `OutputPathResolver` and `OutputPlanner` behavior, not a fake implementation copied into a test. Inspect current behavior first. Characterize whether these independently configured roots flatten to the same output path:

```text
bootstrap/app.php
config/app.php
```

Record how the production planner diagnoses collisions. Passing characterization tests document the present limitation, not a desired permanent contract. If the implementation has already changed, test its actual mapping and remaining gaps rather than restoring the old bug.

## 8. Test-Only Mounted Layout

Keep prototypes in a clearly named spike/test-support namespace. Production commands, schema and normal configuration must not depend on them.

Compare configuration candidates, without approving names solely through this prompt:

- Backward-compatible string/object source entries with `path` and `mount`.
- A separate mounted-source property alongside existing string `source` entries.
- Unified classified project entries for source and resources.

Exercise file roots and directory roots. A candidate may express:

```json
[
  { "path": "app", "mount": "app" },
  { "path": "bootstrap", "mount": "bootstrap" },
  { "path": "config", "mount": "config" },
  { "path": "public", "mount": "public" },
  { "path": "routes", "mount": "routes" },
  { "path": "tests", "mount": "tests" },
  { "path": "artisan", "mount": "artisan" }
]
```

Prove independent output paths:

```text
bootstrap/app.php -> <output>/bootstrap/app.php
config/app.php    -> <output>/config/app.php
app/User.ppphp    -> <output>/app/User.php
```

Preserve legacy string semantics through an empty mount. Distinguish an intentionally empty mount from invalid traversal. Normalize paths to forward-slash relative paths; exercise nested/top-level mounts and deterministic ordering. Reject dot/traversal segments, absolute/drive paths, escapes, ambiguous ownership and conflicting output paths. Case-folded collision behavior must follow existing repository path policy. Test extensionless PHP launchers explicitly: do not assume the current source-suffix contract accepts `artisan` or `yii`.

## 9. Opaque Resources, Runtime State And Ownership

Use an isolated deterministic fixture such as `tests/Fixtures/FrameworkIntegration/LayoutProbe/` containing:

```text
app/Service.ppphp
app/Books/BookController.ppphp
app/Books/Book.php
app/Books/books.view.php
app/Books/database.config.php
app/main.entrypoint.ts
bootstrap/app.php
config/app.php
public/index.php
public/app.css
resources/views/home.blade.php
resources/lang/en/messages.php
routes/web.php
tests/Feature/HomeTest.ppphp
artisan
storage/framework/views/
bootstrap/cache/
```

Add synthetic excluded secrets and runtime state only; never real credentials. Keep the fixture dependency-free for CI.

Classify deterministically:

```text
.ppphp               compile
ordinary .php        copy through the PHP source pipeline
.blade.php           explicit more-specific opaque template rule
.view.php            explicit opaque Tempest template rule, even beside classes
.config.php          ordinary PHP source
other resources      explicit opaque copy or asset-input preservation
runtime directories  explicit empty-directory policy
secrets/live state   exclude from copying
```

PHP language/configuration files are not opaque merely because they live in a resource directory. Do not claim a TypeScript/CSS input is a finished production asset. Asset compilation remains the framework/frontend build tool's job; record which inputs and outputs are packaged.

Required tests:

- `.ppphp`, including `.view.ppphp`, cannot be smuggled through opaque copying.
- Broad `*.php` opaque rules are rejected; approved specific template suffixes may be accepted.
- Co-located `.ppphp`, native `.php`, `.view.php`, `.config.php` and asset input have single, non-overlapping owners.
- Moving a controller and view together preserves relative lookup.
- Source/resource mappings cannot ambiguously overlap or claim the same artifact.
- Resource bytes/hashes remain unchanged, including template-like PHP not parsed as a compilation unit.
- Symlinks/traversal cannot escape the owned roots.
- `.env`, logs, sessions and populated caches are not copied from source.
- Runtime-directory creation cannot collide with files or source roots.
- Created/copied items have deterministic manifest identity proposals.
- Stale resource deletion and failure/recovery semantics are described and exercised in the prototype without bypassing production transactions.
- A clean build does not silently destroy populated runtime state. Compare external writable roots with an explicitly owned lifecycle; materializing empty directories alone is not a persistence strategy.

Show that the candidate can plan the complete layout without collisions and identify what current production code cannot represent. Do not change production manifest format or weaken atomic-build/path-safety guarantees in FI-0.

## 10. Real Framework Probes

Use disposable directories outside the compiler repository. Never vendor full framework installations, generated dependency trees or secrets into `ppphp-src`. Pin versions/revisions, preserve or record lock identities and commands, and separate source inspection from actual execution.

### AssegaiPHP

Inspect the current Core and Console read-only. Study project templates, `WorkspaceManager`, `ProjectTemplateDefaults`, schematics/custom schematics, namespace replacement, dependency installation, serve roots, Web Component watch orchestration, OpenSwoole lifecycle and framework command bridges.

Simulate or execute creation, a representative generator, check/build, tests, HTTP startup and representative migration/queue/API commands where possible. Record precisely where language selection, generated-root selection, source-aware generation and watching should connect. Test or explicitly leave open initial build, failed-build behavior and successful long-lived-process reload. A Core rewrite is not a prerequisite.

### Laravel

Verify the current major and preceding candidate major from official sources. Inspect or execute application creation, Artisan bootstrap, package discovery, a representative `make:*` generator, tests, migrations, a safe synchronous/test queue, route/config optimization, Blade/resources and Composer autoload mappings.

Compare disposable implementations or documented consequences of:

```text
php build/ppphp/artisan ...
root artisan proxy
standalone adapter wrapper around artisan
```

Record argument/exit-code handling, clean install, first build, deleted output, bootstrap failure, recovery, containers and normal/optimized/authoritative Composer autoloading. The standalone compiler recovery path must not depend on successful framework boot. Do not switch root application autoload mappings to missing generated classes before a successful first build.

Preserve both ordinary-PHP and compiled-source entrypoint semantics. Test vendor/config/resource path relocation rather than adding undocumented rewrites to copied PHP. Probe source-free execution of generated output and intended runtime dependencies, without the authored tree or compiler runtime. If the current compiler blocks this, record the actual failure and the required FI-1 capability; do not make a fake compiler or source-tree fallback pass the test.

### Probe Limits

Failed installation/access must record exact command, reason and remaining gate. Continue hermetic prototypes and source inspection. Do not claim a real framework passed because a look-alike fixture passed. Do not claim full FI-0 completion where required execution remains untested; report a limited outcome and concrete next steps.

## 11. Generators And Analysis

For each launch framework compare native generator extension points with template overrides and wrappers. Renaming `.php` is not enough: generated `.ppphp` must satisfy declarations, return types, locals and checked-error rules. Ensure writes target authored source, not output after Composer projection. Declare unsupported generators honestly; ordinary PHP remains an intentional mixed-project fallback, not concealed coverage.

Portable framework analysis uses compiler semantics, dependency declarations read as data and reviewed stubs/PHPDoc. No framework bootstrap, project PHPStan configuration or implicit DB/network access enters normal checks.

An enhanced Laravel/Larastan experiment is separate, explicit and bounded. Inspect its actual bootstrap behavior, run against normalized/generated PHP where feasible, and map diagnostics to original `.ppphp`. Compare portable results, enhanced findings and runtime evidence; classify disagreements instead of treating Larastan as the specification. Do not weaken the language or globally suppress diagnostics to fit framework magic.

## 12. Decisions And ADRs

In `docs/spikes/framework-integration-evidence.md`, decide GO, GO WITH LIMITS or NO-GO for at least:

```text
shared platform model and centralized capability selection
PHP 8.4/8.5 matrix and bounded FI-1 production slices
explicit lint/runtime selection and cross-target cache identity
repeatable support-extension process for future PHP versions
mounted directory roots and file/extensionless roots
opaque resources and specific template classification
empty runtime directories and persistent runtime state
framework profile data and auto-detection
watch/build events and process reload
root command proxies and standalone recovery
mandatory Composer plugin
portable framework stubs
explicit enhanced analysis
```

Each decision needs evidence, tradeoff, security impact, backward-compatibility impact, maintenance cost and next implementation slice. Mandatory Composer plugins remain NO-GO unless contrary evidence is explicitly escalated for an approved policy change, not silently implemented.

Determine available ADR numbers from the repository. Add accepted ADRs only for evidenced conclusions. Incomplete evidence belongs in an open decision, not an accepted ADR. Suggested topics: integration boundary; platform capability model; mounted layout/resource ownership; lifecycle/recovery; portable versus bootstrapped analysis.

## 13. Scope Limits

Do not add production adapter packages, framework compiler dependencies, public mounted/resource/framework configuration, changed normal check/build behavior, executable compiler hooks, new mandatory Composer plugins, framework-magic emulation or empty production scaffolds. Do not modify AssegaiPHP repos in FI-0. Do not change language/CLI/extension/package/namespace identity, the current release version or host minimum as a workaround. Do not advertise prototypes as shipped support.

The wider matrix is research, not a request to implement every adapter. Production shared platform support belongs to FI-1; AssegaiPHP and Laravel adapters to FI-2/FI-3. Preserve every accepted language contract and the repository's normal safety, source-map, transaction, cache and diagnostic conventions.

## 14. Validation

Add focused Pest coverage for real-production characterization and test-only prototypes. Run the platform matrix with explicitly identified PHP executables. Report PASS/FAIL/NOT RUN independently for unit, parser, compiler, lint, runtime and live-framework evidence. Never weaken an existing test or golden to land the spike.

Before reporting completion run the repository-required checks:

```bash
composer validate --strict
composer verify:version
composer analyse
composer test
```

Also run existing documented mixed-application/distribution verifiers where required and practical. Record exact unavailable dependencies or environment failures. A failed or unrun required check must remain visible in the report.

Verify the handoff itself: every required repository input and relative Markdown link exists; the companion amendment contains PV-1 through PV-6; no instruction requires a supplied attachment or prior chat; output paths are identified as outputs. Update references after moves rather than leaving dangling dependencies.

## 15. Commit And Completion Report

Follow the solo-maintainer workflow: local feature branches may isolate work, but consolidate changes into local `develop` before pushing. Push only `develop`, never a remote feature branch. Use a normal non-force push; preserve concurrent work. Do not push `main`, tag or release as part of FI-0. Do not leave completed, validated work only as an attachment.

After required checks pass, commit and push the completed work to `develop`. If checks are blocked, report the exact limitations and do not claim a passing implementation or release gate.

Report changed files, current behavior characterized, prototype behavior proved, live framework commands actually executed, AssegaiPHP/Laravel seams, CakePHP/Tempest/Yii 2/Yii 3 findings, the entire ranked matrix, decision outcomes, exact commands/results, PV-1–PV-6 evidence, remaining gates and the reachable commit SHA. Distinguish documentation changes from compiler capability implementation.

Produce a repository-local FI-1 implementation prompt with actual code locations and bounded production slices. Its required inputs must already be committed in the same handoff, or its requirements must be embedded. Verify them before marking it ready. No successor agent should need private chat attachments to execute the work.
