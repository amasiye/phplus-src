# Changelog

All notable changes to ++PHP are recorded here. Release dates are added only when a version is published.

## Unreleased

No changes have been assigned beyond the prepared MVP candidate.

## 2026.3.1-rc-1 — Prepared, Not Published

### Added

- Typed mutable and readonly locals, typed loop declarations, composite types, erased generics, typed lists and maps, checked errors, and expression-oriented `when`.
- Strict whole-project analysis across mixed PHP and ++PHP source, with compiler-owned type flow and portable dependency declarations.
- Composer runtime projection, atomic mixed builds, source maps, deterministic manifests, and source-free generated execution.
- A versioned incremental cache, deterministic diagnostic pipeline, portable PHP 8.4 signatures, and hardened compiler trust boundaries.

### Changed

- The prepared compiler identity is `2026.3.1-rc-1` and the canonical compiler namespace is `Atatusoft\Ppphp`.
- Native `check` and `build` retain the pinned PHPStan supplemental phase for the MVP release line.

### Fixed

- Generic context, focused declaration analysis, dependency portability, cache validation, bounded subprocesses, and interrupted-build recovery were completed before the candidate.

### Security

- Project, dependency, output, cache, process, and transaction boundaries fail closed and avoid following untrusted symlinks.
- Release assets are deterministic and covered by SHA-256 checksums.

### Known limitations

- Generated output targets PHP 8.4, and the compiler requires PHP 8.4 or newer within the declared `^8.4` range.
- Records, postfix list types, Native Type Members, and attribute factory expressions are post-MVP work.
- This is a prepared release candidate and may change before Stable.
