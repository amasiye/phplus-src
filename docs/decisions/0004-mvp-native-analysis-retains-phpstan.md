# ADR 0004: MVP Native Analysis Retains PHPStan

- Status: Accepted
- Date: 2026-09-02
- Scope: The `2026.3.1` MVP release line

## Context

Compiler-owned technical promotion gates pass for every required MVP and interoperability-boundary capability. PHPStan still supplies optional deep ordinary-PHP body analysis, generator-specific analysis, mature lint, and backend failure evidence. Those behaviors have been part of the verified native path throughout MVP development.

## Decision

Native `ppphp check` and `ppphp build` retain compiler-owned analysis plus the pinned PHPStan supplemental phase for the `2026.3.1` MVP release line. Browser protocol version 2 continues to provide process-free `compilerCore` analysis with full required-capability parity.

The technical status for a possible future switch is **Pass**. The MVP decision is **retain supplemental**. A future native-default change is **not approved**, and current runtime dependency placement is unchanged.

## Consequences

`phpstan/phpstan`, `phpstan/phpdoc-parser`, and `symfony/process` remain normal runtime requirements. No public compiler-only CLI or configuration mode is introduced. Making PHPStan optional or changing the native default requires a separate post-MVP product and packaging decision with an explicit upgrade and failure contract.
