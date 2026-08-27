# PHPStan Integration

> **Status:** Configuration foundation only. The project-analysis adapter is planned for MVP Stage 6.

PHPStan has two independent roles in PHPlus:

1. `phpstan.neon.dist` checks the compiler implementation in this repository.
2. `resources/phpstan/phplus.neon` is the future base configuration for normalized analysis PHP generated from user projects.

PHPStan is a pinned and replaceable backend. Its rule levels, diagnostic wording, and internal APIs do not define PHPlus semantics. PHPlus will own its semantic rules, original source spans, and user-facing diagnostics.

Later stages will invoke PHPStan as a subprocess behind a compiler-owned interface, parse its stable output, and map findings from generated analysis PHP back to original `.phplus` locations. Normal user output must not expose generated paths or raw backend terminology.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative integration contract.
