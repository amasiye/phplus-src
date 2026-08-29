# ++PHP Agent Guide

Read [`docs/ppphp-mvp-end-to-end-plan.md`](docs/ppphp-mvp-end-to-end-plan.md) before implementation work. It is the authoritative execution plan.

- Work one stage at a time. Do not implement a later stage to make an earlier stage appear complete.
- ++PHP compiles to ordinary PHP that runs on the official PHP runtime. Its semantics are defined by its own language contract.
- PHPStan is a pinned, replaceable analysis backend; it does not define ++PHP semantics.
- Do not use regular expressions as the source transformation architecture. Preserve original source spans and useful diagnostics as core requirements.
- Concrete classes belong directly in their owning module or subdomain. Use `Interfaces/`, `Enumerations/`, `Traits/`, `Attributes/`, `Exceptions/`, and `AbstractClasses/` only for those declaration kinds. Do not create `Classes/` directories.
- Name pipeline passes `<Verb><Object>Pass`. Do not add no-op `execute()` methods before a pass and its context are implemented.
- Expose observable object state as typed properties. Use read-only property hooks for derived state instead of zero-argument predicate or accessor methods such as `isSuccessful()` or `files()`.
- Name methods and functions with action-oriented verbs such as `handle`, `process`, `build`, `resolve`, `find`, `read`, or `filter`. Parameterized queries remain methods, but their names must describe the action being performed. PHP-mandated interface and framework method names are exempt.
- Write diagnostic summaries in Title Case.
- Add or update Pest tests for behavior changes.
- Explain the tradeoff before adding a dependency.
- Do not add empty future scaffolds without an immediate need.
- Before reporting completion, run `composer validate --strict`, `composer analyse`, and `composer test`.
