# ++PHP 2026.4 Framework Integration Programme Amendment

> Programme: Framework Integration (FI)
> Target release line: `2026.4.x`
> Required launch integrations: AssegaiPHP and Laravel
> Repository: `atatusoft-ltd/ppphp-src`; working branch: `develop`
> Identity: ++PHP, `.ppphp`, `ppphp`, `ppphp.json`
> Status: Approved planning contract. FI-0 experiments and production implementation are not completed by publishing this document.
> Updated: 2026-09-05

## Authority And Handoff

This repository-local document records the approved framework and multi-version platform amendment to [the canonical execution plan](ppphp-mvp-end-to-end-plan.md). Read them together. This amendment governs the post-MVP FI programme; it does not rewrite completed MVP behavior, change the current release identity, or replace accepted language RFCs. The implementation instructions are in [the self-contained FI-0 prompt](ppphp-framework-integration-fi0-codex-prompt.md).

This amendment and the FI-0 prompt supersede the attachment-only framework handoff, including the alternate Stage 17A prompt. No chat attachment or prior conversation is required. The older roadmap, spike and prompt attachments are not additional prerequisites. The detailed experimental contract is embedded in the FI-0 prompt. Consolidation removes duplicate handoff documents, not the approved requirements.

## 1. Product Directive

The 2026.4 release line must establish ++PHP as a practical application language, not merely a compiler manually wired into generic Composer projects.

Required outcomes:

- AssegaiPHP: first-class native integration.
- Laravel: first-class official external integration.

First-class means project creation or reversible adoption, mixed `.php`/`.ppphp` source, framework-aware generators, build/watch/test workflows, HTTP and console execution, migrations and queues, source-mapped diagnostics, source-free deployment, recovery without framework bootstrap, documented supported versions and uninstall/upgrade behavior. A wrapper around `ppphp build` alone does not qualify.

AssegaiPHP and Laravel are release gates for `2026.4.x`. Other language-ergonomics work is not a prerequisite and must not consume the capacity required to finish these integrations. Native PHP remains supported. Rewriting AssegaiPHP Core in ++PHP is not required.

## 1A. Multi-Version PHP Platform Contract

The initial PHP implementation baseline is not the architectural ceiling. Multi-version support is shared compiler work, not a workaround owned by Tempest or another adapter.

### Independent Compatibility Dimensions

| Dimension | Responsibility |
|---|---|
| Compiler host | Declare which PHP versions execute the compiler and its tooling dependencies. Do not raise the minimum merely because a project targets a newer runtime. |
| Source and dependency syntax | Parse and model supported project and dependency syntax as data. Host-interpreter capability is not parser capability. |
| Platform knowledge | Select versioned built-in signatures and relevant extension capabilities for the analysis platform, not by reflecting the host. |
| Emission target | Validate features and emit PHP legal for the selected target. Reject unsupported lowering; do not promise arbitrary backward transpilation. |
| Application runtime | Validate the complete application's requirements, including unchanged native PHP, Composer dependencies and required extensions, not just emitted syntax. |

An older emitted-syntax target does not make newer dependencies runnable on an older interpreter. Native `.php` remains byte-for-byte copied. Changing a target string cannot make incompatible native or vendor syntax compatible.

### Shared Foundation Work

FI-0 inventories version assumptions in configuration and schema validation, parsers, declaration indexes, built-in signatures, lowering, lint subprocess selection, caches, manifests, Composer checks, tooling dependencies and CI. Record exact locations and classify each as a host requirement, platform capability, historical fixture pin or accidental restriction. Do not mechanically remove legitimate pins.

FI-1 owns a centralized, versioned platform-capability model and its implementation. PHP 8.4 and 8.5 are the initial concrete evidence pair, not a permanent two-version design. PHP 8.5 support is scheduled in the shared `2026.4.x` foundation. It is not optional work contingent on implementing Tempest. AssegaiPHP and Laravel remain the two launch integrations; other frameworks inform foundation requirements before their adapters are implemented.

Supporting another PHP release follows a repeatable process: review authoritative language/API changes; update parser, semantic and lowering capabilities where required; generate and verify matching signature data; add real positive and negative fixtures; execute the compatibility matrix; then publish supported combinations. Use data-only updates only when genuinely sufficient. Unknown future versions remain unsupported until validated; there is no wildcard forward-compatibility promise.

### PV-1 Through PV-6

| Gate | Required Evidence |
|---|---|
| PV-1 — Version-sensitive analysis | Check the same project against 8.4 and 8.5 platform profiles using genuine version-sensitive syntax/API fixtures. Appropriate acceptance and rejection must differ. Accepting another configuration string is insufficient. |
| PV-2 — Host/platform separation | Exercise a declared cross-version combination, including an 8.4 compiler host with an 8.5 project platform where tooling dependencies permit. Report separately from same-version runs. Failure becomes a bounded shared-platform task, not a hidden host-minimum increase. |
| PV-3 — Correct validation/runtime | Select a compatible PHP executable explicitly for generated-output lint and execute on every runtime claimed by the row. Record actual interpreter identities. Never silently substitute `PHP_BINARY`. |
| PV-4 — Dependency requirements | Reject incompatible Composer/native-PHP/runtime combinations, unavailable required extensions and unsupported targets. Never use `--ignore-platform-reqs` as compatibility evidence. |
| PV-5 — Evidence isolation | Changes to platform target, signature data, relevant dependency metadata or emitter capabilities invalidate incompatible analysis/build evidence. Manifests record relevant platform identity. |
| PV-6 — Extensibility | Prove profile selection and capability lookup use the shared model, not framework-specific version switches. Synthetic unknown-version tests prove rejection or registry behavior, not real support for an unreleased version. |

Every row records compiler revision, host PHP, parser capabilities, signature-package identity, emission target, application runtime, dependency-lock identity, relevant extensions, exact command, exit status and `PASS`, `FAIL` or `NOT RUN`. An expected-rejection test passes only when the expected diagnostic actually occurs.

FI-0 supplies executable characterization/prototype tests and a runnable matrix harness. It does not need to implement all production support. FI-1 cannot advertise a target until real analysis, lint and runtime evidence passes for the advertised combination. Unavailable interpreters leave rows NOT RUN and support gates open. Infrastructure limits do not justify deleting platform work or lowering framework priority solely to fit the initial baseline.

### Scope And Ownership

Preserve current release behavior and the compiler-host minimum during FI-0 unless another current, explicit canonical decision authorizes a change. This does not prohibit test-only multi-version prototypes, isolated PHP 8.5 environments or the required FI-1 work. Portable analysis remains non-executing; framework bootstraps belong to explicit runtime probes. This contract supersedes older wording deferring PHP-version work behind a Tempest-only gate.

## 2. Preserve Language Stages

FI work must preserve accepted language contracts and follow the [current owner-approved Stage 15 schedule](ppphp-mvp-end-to-end-plan.md#stage-15--immutable-records-native-type-ergonomics-and-declarative-framework-metadata). The project owner may regroup or reorder unimplemented language work; reconcile approved changes across the plan, RFCs and handoffs rather than freezing historical identifiers. FI labels do not independently renumber language work, and a schedule is not implementation status.

The separate release-oriented programme is:

| Programme Item | Outcome |
|---|---|
| FI-0 | Framework Integration Evidence Spike |
| FI-1 | Multi-Version Platform, Runtime Layout And Resource Pipeline |
| FI-2 | AssegaiPHP Native Integration |
| FI-3 | Laravel Official Integration |
| FI-4 | Framework Queue Validation |

FI labels identify cross-repository product work, not new language syntax or replacements for language-stage contracts. The alternate attachment label Stage 17A is not another execution track.

## 3. MVP Boundary

Framework-specific compiler plugins and framework-magic emulation remain outside the ++PHP language core and the 2026.3 MVP. Official post-MVP adapters may integrate project creation, generation, build/watch/test/deployment lifecycles, declarative metadata, safe stubs and framework-native commands without redefining ++PHP semantics.

Do not teach the language that Eloquent, Doctrine, Yii ActiveRecord or a CMS service locator changes PHP semantics. Do make framework projects, generators, commands, analysis metadata and deployments understand compilation.

## 4. Programme Sequence And Acceptance

### FI-0 — Framework Integration Evidence Spike

Prove or falsify the architecture before exposing adapter APIs or finalizing configuration syntax. Required evidence includes the actual multi-root collision; a backward-compatible mounted-layout prototype; opaque resources and runtime-directory ownership; first-build/recovery/uninstall sequences; AssegaiPHP and Laravel command and generator seams; the complete candidate matrix; and executable PV-1–PV-6 characterization/prototype tests.

Give GO, GO WITH LIMITS or NO-GO decisions per proposed foundation capability. Keep experiments internal/test-only. Prose, widened schema enums and mocked interpreters do not establish version support. Exact implementation work is specified in the FI-0 prompt.

### FI-1 — Shared Foundation

Implement evidenced shared capabilities:

- Centralized platform selection, PHP 8.4/8.5 parsing and built-in signatures, target-aware lowering, explicit lint/runtime selection, Composer/platform validation, cache/manifest identity and CI.
- Layout-preserving file and directory source mounts, opaque resources, specific template-suffix classification, empty runtime-directory materialization and safe resource-aware clean.
- Deterministic manifest ownership, structured watch/build success/failure events and framework-profile capability/version validation.

Exact configuration names remain decisions for the spike. Existing string entries such as `"source": ["src"]` retain their current output semantics; mounted entries are opt-in. Do not force old projects onto a new layout.

Every file has exactly one classification and output owner. `.ppphp` cannot be copied opaquely. A broad `*.php` resource rule cannot hide PHP code. Explicit more-specific template rules may classify `.blade.php` or `.view.php` as opaque; ordinary `.config.php` remains PHP source. Paths remain contained and symlink-safe. Secrets, logs, sessions and populated caches are not copied. Copied resources and created directories have deterministic ownership.

Runtime-state policy must be explicit: do not place live user data in a tree destructively replaced by a clean build. Empty-directory materialization is not permission to delete populated runtime state on the next build. Preserve or externalize state through a tested lifecycle, with build/clean and relocation evidence.

### FI-2 — AssegaiPHP Native Integration

Target normal framework workflows: language selection in project creation; valid language-aware templates and schematics; compiler development dependency; `ppphp.json`; Composer projection; serve/watch/test/build; databases and migrations; queues; API documentation/client generation; Web Component watch coordination; normal PHP and supported OpenSwoole lifecycle; production packaging; standalone recovery.

Proposed command examples, not claims of existing support:

```bash
assegai new my-app --language=ppphp
assegai generate resource users
assegai serve --dev
assegai test
assegai build
```

Offer PHP and ++PHP interactively, with ++PHP recommended once stable. Generators must emit valid `.ppphp`, not merely rename untyped PHP. They write authored source even when runtime paths point into output.

Release gate: a clean checkout creates a project, generates representative artifacts, checks/tests/serves it, runs framework commands, builds it and executes source-free generated output. A Core rewrite is not a prerequisite. Existing PHP behavior must remain usable.

### FI-3 — Laravel Official Integration

Use an ordinary PHP package/service provider for framework-native behavior and a standalone recovery path that does not require Laravel to boot. Package names remain provisional until publication.

Required: reversible dry-run installer, integration profile, doctor/check/build/dev commands, generator support, portable metadata/stubs, original-source diagnostics, version matrix, upgrades/uninstall and source-free fixture.

First build must precede switching Composer mappings to generated application classes. Prove both clean-install and missing-output recovery; a provider cannot repair a bootstrap that cannot load it. Compare generated-root Artisan, a root proxy and an adapter wrapper before choosing ergonomics. Test containers, arguments/exit codes, Composer optimization and source-free execution.

Preserve `app`, `bootstrap`, `config`, `database`, `public`, `resources`, `routes`, tests and the console entrypoint as required by the selected fixture. Copy Blade opaquely; do not copy `.env`, dependency trees or live logs/caches. Resolve vendor, writable-state and public-document-root policy deliberately. Build before route/config/discovery/cache optimization; test final deployment paths.

The fixture covers HTTP, typed DI service, a controller, Eloquent interaction, middleware or form request, a console command, job, migration/seeder, tests, Blade, assets and generators. Verify normal and optimized/authoritative Composer autoloading, framework optimization, source-free execution and uninstall/revert.

Portable checks never bootstrap Laravel. Enhanced Larastan analysis, if investigated, is an explicit bounded process against normalized/generated PHP, with mapped diagnostics and a separate execution/security contract. It does not define ++PHP semantics or justify weakening the default checker.

### FI-4 — Queue Validation

Evaluate separate candidates: AssegaiPHP, Laravel, Symfony, CakePHP, Tempest, Yii 2, Yii 3, Drupal, WordPress and Joomla.

Initial prioritization after the two launch integrations: Symfony, CakePHP, conditional Tempest, with Yii 2 and Yii 3 independently assessed. The CMS track is Drupal, WordPress and Joomla. Tempest research begins early; its platform requirements enter FI-1 immediately. WordPress research begins early because plugin/theme packaging is different from whole-application compilation. Rankings and difficulty estimates are provisional until measured; no additional candidate becomes a third launch gate.

## 5. Framework-Specific Research Contract

CakePHP: inspect plugin hooks, `bin/cake`, Bake themes/templates/events/commands, ORM contracts, PHP templates, cache/log state and source-aware generator destinations. Start from the actual current supported release; do not claim an untested future major. It is a high-priority post-Laravel general-framework target.

Yii 2: inspect basic and advanced templates separately, root `yii`, Gii generators/templates, Composer extension configuration, aliases (`@app`, `@runtime`, `@vendor`), web/console configuration, PHP views, assets/runtime state and ActiveRecord dynamic contracts. Evaluate against explicit platform profiles, not the old baseline as a ceiling.

Yii 3: inspect the actual modular package composition and official web/API/console templates independently of Yii 2. Record generator seams, assembled configuration, long-lived runners and exact package combinations. One template does not prove all Yii 3 applications supported. Recommend which generation receives a stable adapter without merging their matrix rows.

Tempest: research Composer-aware reflective discovery, explicit locations, initializers, package installers and generator/import tools from primary sources. Do not assume a Laravel-style provider contract. Pin the actually inspected/resolved version and separate documentation from installation evidence.

Required Tempest concerns:

- Discovery loads generated/copied application PHP and intended vendor code, never `.ppphp`, duplicate original/output declarations, tests or stale staging paths.
- Concrete attributes and signatures survive; erased generics do not become runtime-reflectable.
- Co-located `.view.php` files preserve relative view lookup. Configuration PHP remains source; Tempest owns view syntax/compilation. ++PHP syntax inside views is not promised.
- Build precedes discovery/cache generation. Test edit/add/rename/delete invalidation, missing-output recovery and discovery failure after successful compilation. Never reload inconsistent code/cache state.
- Mutable caches are not copied from the source workspace or silently destroyed by output replacement. Verify cache validity at final source-free deployment locations.
- Installers/generators write authored source, not runtime output. Verify parser/import tooling before claiming `.ppphp` generation.
- Application-frame mapping to `.ppphp` and view-frame mapping to `.view.php` remain distinct. Do not claim debugger breakpoints without testing.

FI-0 requires research, a dependency-free co-location probe and a later full Tempest fixture plan containing route, DI, event handler, console command, view, configuration and migration. A real Tempest installation is optional after the two launch probes; the shared PHP 8.4/8.5 experiment is mandatory regardless. Missing runtimes leave NOT RUN cells, not deleted platform tasks.

Symfony, Drupal, WordPress and Joomla: inspect actual package/bundle/module/plugin/console and generation mechanisms. Symfony is a whole-application/bundle target. Drupal initially targets custom modules/themes; WordPress plugins/themes; Joomla installable extensions. Do not compile or rewrite CMS cores to obtain a passing fixture. Recheck current official source and support requirements when executing the spike.

## 6. Evidence And Maintenance Matrix

For each framework record inspected version/revision/date; all five platform dimensions; creation and installation; extension seam; console; generators; source/resource/template/writable/cache roots; Composer shape; discovery; long-lived runtime concerns; portable and enhanced analysis options; likely package shape; maintenance cost; difficulty; proposed priority; and confidence/evidence status.

Use executable evidence where available and label source/documentation findings separately. No precise popularity or effort claim is established by this planning document.

## 7. Provisional Release Allocation

| Release Slot | Proposed Work |
|---|---|
| `2026.4.1` | Shared multi-version foundation, including PHP 8.5 evidence; layout/resource/lifecycle capabilities; AssegaiPHP native integration and source-free fixture. |
| `2026.4.2` | Laravel preview: adoption, layout, command/recovery path, initial generators and portable analysis. |
| `2026.4.3` | Laravel stable: settled ergonomics, declared generator/version coverage, Composer optimization, source-free deployment, upgrade/uninstall and CI. |

These are planning slots, not permission to waive gates or assertions that releases already exist.

## 8. Plan Synchronization

This amendment is an explicit part of the maintainer planning contract through `AGENTS.md`. During FI-0, add or reconcile a concise `2026.4 Framework Integration Programme` section in the canonical execution plan linking here, without copying contradictory historical attachments or overwriting existing language stages. Record FI-0 through FI-4, the two launch gates, the shared platform requirement, PV-1–PV-6, separate Yii targets and early Tempest research. Do not mark experiments complete until evidence exists.

## 9. Primary Research Entry Points

These URLs are research inputs, not fresh compatibility claims. Inspect current content and record revisions at execution time.

- Composer scripts: https://getcomposer.org/doc/articles/scripts.md
- Composer configuration: https://getcomposer.org/doc/06-config.md
- Laravel documentation: https://laravel.com/docs
- Symfony bundles: https://symfony.com/doc/current/bundles.html
- CakePHP plugins: https://book.cakephp.org/5/en/plugins.html
- CakePHP Bake: https://book.cakephp.org/bake/3/
- Yii Gii: https://www.yiiframework.com/extension/yiisoft/yii2-gii
- Yii 3: https://yii3.yiiframework.com/
- Tempest installation: https://tempestphp.com/3.x/getting-started/installation
- Tempest discovery: https://tempestphp.com/3.x/essentials/discovery
- Tempest packages: https://tempestphp.com/3.x/extra-topics/package-development
- Tempest views: https://tempestphp.com/3.x/essentials/views
- Tempest view maps: https://tempestphp.com/blog/view-source-mapping
- Tempest deployments: https://tempestphp.com/3.x/extra-topics/deployments
- WordPress plugins: https://developer.wordpress.org/plugins/
- Drupal development: https://www.drupal.org/docs/develop
- Joomla extensions: https://manual.joomla.org/docs/building-extensions/

## 10. Completion Discipline

A planning commit repairs the handoff only. Production platform support and framework integration require their own code, tests and release gates. Every implementation report must name changed files, exact executed commands/results, remaining NOT RUN rows, decisions and a reachable commit on `develop`. Never claim a downloaded attachment is a repository change.
