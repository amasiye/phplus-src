# ADR 0001: Compiler-Owned Project Analysis

- Status: Accepted
- Date: 2026-09-01
- Scope: Stage 13A

## Context

The native `ppphp check` and `ppphp build` pipeline combines compiler-owned syntax and semantics with a pinned PHPStan backend. PHPStan also checks the compiler implementation through `phpstan.neon.dist`; that development role is unrelated to user-project analysis. The first browser spike proved that the production PHPStan CLI aborts in PHP 8.4 WASM at `_getcontext`, even when invoked as a top-level command without a spawn adapter. Compiler-owned parsing and semantics have no such process requirement.

Before Stage 13A, `ProjectChecker::prepare()` mixed selected-source parsing, declaration-context collection, semantic analysis, lowering, analysis-workspace creation, and PHPStan planning. A successful compiler semantic result therefore could not be represented without an `AnalysisProject`, and the old flow analyzed the selected sources again after workspace preparation.

## Decision

Introduce `CompilerProjectAnalyzer` and `CompilerProjectAnalysis` as the authoritative in-process compiler-core boundary. That result owns the project selection, selected parse result, safe unselected declaration context, semantic models, processed diagnostics, `compilerCore` completeness, and the catalog-derived list of uncovered required capabilities. It has no dependency on `AnalysisProject`, the PHPStan adapter, Symfony Process, subprocess state, or analysis-workspace files.

Treat analysis workspace materialization and PHPStan invocation as a separate supplemental phase. `ProjectChecker` still runs that phase for normal native `check` and `build`; its guarantees and public behavior do not change. PHPStan is constructed lazily only when the supplemental path is actually requested. Browser protocol version 2 calls the compiler-owned analyzer directly and supports Check only. It returns neither a command nor a continuation. Browser protocol version 1 remains compatible for the existing process-oriented experiment.

Treat the typed capability catalog and differential fixtures as evidence. PHPStan is an oracle for mature PHP behavior, not the ++PHP specification. Disagreements are classified as compiler gaps, backend gaps, language-policy differences, optional lint, or fixture errors and are reviewed rather than blindly copied.

For ordinary PHP, adopt Model B as the target contract and Model C as the migration vehicle: compiler-owned analysis must be complete for strict ++PHP and for ordinary-PHP declarations/contracts crossing the language boundary; deep ordinary-PHP body analysis remains supplemental until its required subset is deliberately promoted. A `compilerCore` result is never presented as full while required catalog gaps remain.

## Alternatives Considered

- Full mixed-body parity immediately would offer the simplest user promise, but it would require building a broad PHP analyzer before the measured release-critical gaps are isolated.
- Keeping compiler and backend orchestration inseparable would preserve the old implementation shape but would make portable in-process checking impossible and repeat selected parsing/semantic work.
- Replacing PHPStan or calling undocumented PHPStan APIs in process would increase compatibility and maintenance risk while weakening the existing full native path.
- A no-op backend would fabricate completeness and was rejected.
- Exposing a public compiler-only CLI/configuration mode in Stage 13A would let users mistake measured partial coverage for the full guarantee and was rejected.

## Consequences

The browser can perform bounded one-shot compiler checking in a single PHP-WASM process. Native checks and builds still use PHPStan. The codebase now has two explicit success dimensions: whether the requested analysis produced errors and whether its coverage is `compilerCore` or `full`. Ten required compiler-only gaps remain visible in catalog version 1. Stage 13B is selected from those gaps rather than from aspiration.

The current runtime dependency placement does not change. `phpstan/phpstan` remains required while the full native path depends on it; `phpstan/phpdoc-parser` remains a direct compiler parsing dependency; `symfony/process` remains required for PHPStan and production `php -l`. Optionalization is permitted only after the promotion gates in the analyzer-independence plan pass.

## Revisit Conditions

Revisit this decision when every required catalog capability is compiler Complete, the parity corpus has no backend-only blocking finding, canonical mixed projects and shopping-cart checks pass without supplemental analysis, and supported consumers no longer require protocol version 1. Any default switch requires a separate decision and may not be inferred from Stage 13A.
