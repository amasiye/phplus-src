# ++PHP Agent Guide

Read [the MVP end-to-end plan](docs/ppphp-mvp-end-to-end-plan.md) before implementation work. It is the authoritative execution plan.

- Work one stage at a time. Do not implement a later stage to make an earlier stage appear complete.
- ++PHP compiles to ordinary PHP that runs on the official PHP runtime. Its semantics are defined by its own language contract.
- Typed locals, typed loop bindings, strict project analysis, checked errors, composite types, erased generics, typed arrays, value-producing `when` expressions, atomic production builds, full mixed-project interoperability validation, and the catalog-owned diagnostic pipeline are active. Stage 13 is the next implementation boundary.
- PHPStan is a pinned, replaceable analysis backend; it does not define ++PHP semantics.
- Never load user PHPStan configuration, project autoload entrypoints, Composer scripts, or application bootstrap files during analysis. Supply valid context by scanning source as data.
- Treat the configured output root as compiler-owned generated state. Production writes go through the compiler transaction, manifest, source-map, lock, and lint contracts; never patch committed output directly.
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
- Explain the tradeoff before adding a dependency.
- Do not add empty future scaffolds without an immediate need.
- Before reporting completion, run composer validate --strict, composer analyse, and composer test.
