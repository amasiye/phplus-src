# ++PHP Agent Guide

Read [the MVP end-to-end plan](docs/ppphp-mvp-end-to-end-plan.md) before implementation work. It is the authoritative execution plan.

- Work one stage at a time. Do not implement a later stage to make an earlier stage appear complete.
- ++PHP compiles to ordinary PHP that runs on the official PHP runtime. Its semantics are defined by its own language contract.
- Typed locals, typed loop bindings, strict project analysis, checked errors, composite types, erased generics, typed arrays, value-producing `when` expressions, atomic recoverable production builds, full mixed-project interoperability validation, and the catalog-owned diagnostic pipeline are active. Stages 13A–13D, the post-Stage-13C portable-dependency completion gate, and Stage 14A are complete; Stage 14B publication is next.
- Quarterly CalVer is settled. The current compiler version is `2026.3.1-rc-1`; Stable is `YYYY.Q.R`, Release Candidate is `YYYY.Q.R-rc-N`, and Development is the separate `dev-YYYY.Q.R` channel.
- Never replace quarterly CalVer with SemVer or a month-based calendar. Never call `R` a patch version, rewrite Development as a suffix, merge Development with Release Candidate, or add an unapproved public suffix.
- Release selection defaults to Stable. Release Candidate and Development require an explicit channel or exact version, supplied channel and version must match, and selection never falls back across channels.
- Canonical versions and tags have no `v` prefix. Do not change the current release train from the wall clock, publish mutable schema URLs, or document untested Composer prerelease commands.
- Ordinary compiler commands never perform update checks or release-catalog network requests. Do not add an installer, self-update command, or release publication behavior outside its approved stage.
- PHPStan is a pinned, replaceable analysis backend; it does not define ++PHP semantics.
- Keep compiler-owned project analysis independent of `AnalysisProject`, PHPStan, and process launching. Treat `compilerCore` success as incomplete while the capability catalog reports required gaps.
- Keep normal `check` and `build` on the full supplemental path under ADR 0004. Do not expose a public compiler-only mode or change the native default without an explicit post-MVP decision.
- Treat the content-addressed cache as evidence, never as authority. Validate hashes and identities, regard corruption as a safe miss, keep compiler-core and supplemental records separate, and never fabricate a semantic model from persisted data.
- Every Complete or Partial analysis-capability claim requires executable parity evidence. Review golden changes and use `UPDATE_ANALYZER_PARITY=1` only for intentional updates.
- Never load user PHPStan configuration, project autoload entrypoints, Composer scripts, or application bootstrap files during analysis. Supply valid context by scanning source as data.
- Treat the configured output root as compiler-owned generated state. Production writes go through the stable operation lock, durable transaction journal, manifest, source-map, cache-evidence, and lint contracts; recover an interrupted transaction before mutation and never patch committed output directly.
- Focused checks report selected-source failures while valid unselected sources provide context; unrelated invalid sources must remain isolated.
- Do not use regular expressions as the source transformation architecture. Preserve original source spans and useful diagnostics as core requirements.
- Concrete classes belong directly in their owning module or subdomain. Use Interfaces/, Enumerations/, Traits/, Attributes/, Exceptions/, and AbstractClasses/ only for those declaration kinds. Do not create Classes/ directories.
- Name pipeline passes <Verb><Object>Pass. Do not add no-op execute operations before a pass and its context are implemented.
- Expose observable object state as typed properties. Use read-only property hooks for derived state instead of zero-argument predicate or accessor methods.
- Name methods and functions with action-oriented verbs such as handle, process, build, resolve, find, read, or filter. Parameterized queries remain methods, but their names must describe the action being performed. PHP-mandated interface and framework method names are exempt.
- Write diagnostic summaries in Title Case.
- Define diagnostic family, status, severity, and title only in `DiagnosticCatalog`; production sites supply message, labels, help, debug data, origin, and identity.
- Keep normal diagnostics compiler-oriented. Backend, parser, subprocess, workspace, and generated-analysis details belong behind `--debug`.
- Add or update Pest tests and public documentation for behavior changes.
- Keep public release documentation focused on shipped user-visible behavior, not the internal stage, prompt, agent, merge, or acceptance process that produced it. Stage terminology belongs in maintainer plans, decisions, RFCs, and release runbooks; README files, changelogs, release notes, getting-started and migration guides, security policies, package metadata, website copy, and marketplace copy remain release-oriented.
- Explain the tradeoff before adding a dependency.
- Do not add empty future scaffolds without an immediate need.
- Before reporting completion, run composer validate --strict, composer verify:version, composer analyse, and composer test.
