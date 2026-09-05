# 2026.4 Framework Integration Feasibility

Inspection: 2026-09-05, compiler `3a0cffcc291e0b128b96cc7ab13529fedff022f2` plus the test-only experiments in this change. **GO WITH LIMITS for foundation implementation; not a framework-support or release certificate.**

Inputs are the [repository-local work order](../ppphp-framework-integration-fi0-codex-prompt.md), [approved amendment](../ppphp-framework-integration-plan-amendment.md) and [canonical plan](../ppphp-mvp-end-to-end-plan.md). Results live in the [evidence report](framework-integration-evidence.md), [candidate matrix](framework-integration-matrix.md) and [executable successor prompt](framework-integration-fi1-codex-prompt.md). No private attachments are prerequisites.

## Product Gate And Scope

First-class AssegaiPHP and Laravel integration must ship during `2026.4.x`. First-class means reversible creation/adoption, valid source-aware generators, mixed PHP/++PHP, development/watch, tests, HTTP/console, migrations/queues, production packaging, source-free execution, original-source diagnostics, recovery, declared version coverage and removal. Merely wrapping a build command does not meet this definition.

Framework-specific compiler plugins and framework-magic emulation remain outside the language core and the `2026.3` MVP. Official adapters may integrate creation, generation, build/watch/test/deployment, declarative metadata, safe stubs and native commands without redefining language semantics. Core rewrites and new language features are not prerequisites. Native PHP remains intentional supported source, not a disguised failed conversion.

This spike changes no production classes, configuration/schema, release metadata or dependencies. `2026.3.1-rc-2` remains unchanged; Stage 14B publication is not completed here. Preserve Stage 15A–15D and later accepted RFC amendments exactly. The existing plan/RFC index has differing historical schedule descriptions (including accepted Scalar Objects and List And Map Path Access); reconciling those language schedules is outside FI-0. FI identifiers do not renumber them.

| Item | Deliverable | Proposed slot |
| --- | --- | --- |
| FI-0 — Framework Integration Evidence Spike | Reproducible experiments, negative evidence, decisions and handoff | This maintainer change |
| FI-1 — Multi-Version Platform, Runtime Layout And Resource Pipeline | Shared PHP 8.4/8.5 capability support, mounts, resources, ownership and lifecycle | `2026.4.1` |
| FI-2 — AssegaiPHP Native Integration | Native creation/generation/commands, watch and source-free fixture | `2026.4.1` |
| FI-3 — Laravel Official Integration | Adoption/recovery preview; then full declared coverage and deployment | Preview `2026.4.2`, stable `2026.4.3` |
| FI-4 — Framework Queue Validation | Separate general-framework and CMS qualification | After the launch integrations; early research now |

Slots are provisional capacity allocations, not permission to waive gates. Queue: Symfony, CakePHP, conditional Tempest, Yii 2 and Yii 3 separately; CMS track Drupal, WordPress, Joomla. Investigate WordPress packaging early. Tempest is not a third launch gate; its PHP 8.5 requirement belongs to FI-1 regardless of adapter timing.

## Shared Platform Contract

Five independent dimensions are explicit: compiler host, project/dependency syntax, platform signatures/extensions, emitted syntax, and complete-application runtime. Do not reflect host APIs to decide the analysis platform. Do not claim that old emitted syntax makes newer vendor code runnable. Unchanged native PHP is never backward-transpiled to manufacture compatibility.

The prototype registry distinguishes 8.4/8.5 pipe syntax and `array_first`, with real property-hook and `array_find` positive controls. It executes on both identified hosts and explicitly selected runtimes, including host 8.4/platform 8.5. It is a small reviewed capability specimen, **not** a full signature package, type checker, lowering implementation or Composer solver. The [PV table](framework-integration-evidence.md#platform-evidence) separates that evidence from missing production support. Unknown versions fail closed; adding a future version requires authoritative change review, necessary compiler changes, generated/verified signatures, positive/negative fixtures, actual matrix execution, and only then publication.

## Framework-Neutral Layout

Production roots are flattened relative to each source directory. Real `ProjectSource`, `OutputPathResolver` and `OutputPlanner` tests prove that independent `bootstrap/app.php` and `config/app.php` collide at `app.php`. Configuration also rejects an extensionless file root such as `artisan` as not a directory. Existing Composer projection repeats the root-stripping model; adding mounts only in emission would leave autoloading inconsistent.

The test-only planner preserves directory mounts, file mounts, empty legacy mounts, nested paths and case-folded collision checks. It distinguishes compiled ++PHP, native PHP, specific opaque templates, other resource bytes and empty runtime directories. `.config.php` and PHP language files remain PHP even under `resources/`; `.view.ppphp` compiles. TypeScript is an asset **input**, not a compiled production bundle. Asset output belongs to the frontend/framework build tool.

Configuration alternatives remain open:

| Candidate | Benefit | Cost / unresolved point |
| --- | --- | --- |
| Mixed string/object `source` entries | Small migration; strings retain empty mounts | Schema/diagnostic union complexity; how file roots declare their kind |
| Separate mounted-source property | Existing field remains simple | Two ownership lists can conflict; one planner must merge them |
| Unified classified project entries | One ownership model for files/resources | Larger public API and migration surface; avoid premature generality |

Prefer one internal ownership plan regardless of eventual public spelling. The prototype is not approval of any schema name. Its conservative suffix allowlist and state-directory exclusions are fixture policies, not a production framework autodetector. It rejects symlinks entirely; safe in-root symlink support is not claimed.

Future manifests need operation/classification, mount/source identity, content hash, empty-directory ownership, complete platform/signature/emitter identity and generation identity. Resource renames/deletions must retire only previously owned artifacts through existing transactions. Partial builds must never certify a complete deployable application accidentally.

## Bootstrap, Recovery And State

Install dependencies without first redirecting authored application classes to nonexistent output. Establish a validated first build, then perform the reversible Composer projection and framework preparation. Keep an independent compiler/recovery entrypoint that does not bootstrap the framework. Record source autoload metadata for generators and uninstall; reject divergent edits rather than overwriting them.

| Command model | Consequence |
| --- | --- |
| `php build/ppphp/artisan ...` | Clear generated root; cannot run before the first build or after output loss; relative `vendor` and writable paths must actually exist |
| Root `artisan` proxy | Familiar command; must preserve argv/stdin/stdout/stderr/exit/signals and choose a complete generation; cannot repair itself through a provider that requires broken application bootstrap |
| Standalone adapter wrapper | Can build/doctor/recover before requiring application code; additional executable, but avoids bootstrap deadlock; strongest initial candidate |

No proxy implementation is accepted by this spike. Native entrypoints must remain byte-for-byte copied; do not silently rewrite `__DIR__` in ordinary PHP. The initial Laravel `artisan` requires root-relative `vendor/autoload.php` and `bootstrap/app.php`; merely copying it deeper cannot work. Source-free packaging must place dependencies and resources according to the chosen deployment layout, then regenerate location-sensitive framework caches at the final path. Containers need explicit mount/document-root policies, not developer-machine absolute paths.

Compilation success does not mean discovery/cache preparation success. A candidate generation is selectable only when both succeed. The in-memory lifecycle experiment shows that failed preparation leaves the previous selection intact and identifies stale resources only from the previous owner set. It does **not** prove filesystem crash recovery, cache relocation or process reload; those are explicit FI-1/FI-2/FI-3 gates.

Prefer writable state outside replaced output. Empty-directory creation alone provides no persistence. Never copy `.env`, logs, sessions or populated caches from source; never treat external live state as cleanable output. Framework-specific cache paths may require configuration or deployment links, which the generic planner does not create. Prove add/rename/delete, clean, failed build, failed discovery, restart and relocation before live reload. Do not serve mismatched code/cache generations.

## Generators And Analysis

Assegai native schematics and Laravel generator/provider hooks are preferable to a compiler-owned framework parser. Template override is useful only where the result is valid ++PHP. Renaming `.php` fails to add typed locals, return contracts and checked errors. Native generators must target authored paths after runtime projection. Unsupported generators should deliberately emit ordinary PHP, with coverage documented.

Portable analysis remains non-executing: compiler semantics, dependency declarations as data, reviewed stubs/PHPDoc. It must never silently load project PHPStan configuration, database connections, autoload scripts or application bootstraps. Eloquent/Doctrine/ActiveRecord magic is not language semantics.

An explicit enhanced process is a separate trust boundary. Inspected Larastan bootstrap requires `bootstrap/app.php` and boots the console kernel. Run only with explicit selection, bounded resources and environment; use normalized/generated PHP and map diagnostics back. Current full application compilation failures prevent claiming a normalized enhanced-analysis comparison in this spike. No suppressions or baseline were added.

## Overall Outcome

GO WITH LIMITS: implement the shared foundation in bounded slices, beginning with platform selection and measured dependency-context resource behavior. GO for preserving isolation and deterministic ownership. NO-GO for advertising either launch integration today, for implicit framework bootstrap during checks, or for a mandatory new Composer plugin.

The [evidence report](framework-integration-evidence.md) records failures and NOT RUN cells rather than converting successful PHP baseline commands into ++PHP support claims. The successor prompt is repository-contained; its production gates remain unfulfilled.
