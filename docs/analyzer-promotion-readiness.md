# Analyzer Promotion Readiness

Stage 13D evaluates whether compiler-owned analysis is technically ready for a later default-analyzer decision. It does not make that product decision.

The deterministic `composer verify:analyzer-promotion` report currently records:

- technical gates: **Pass**;
- product decision: **Pending**;
- native default: **PHPStan supplemental path**; and
- recommended next action: an explicit analyzer-default decision during or before Stage 14.

The gates cover every required and boundary capability in catalog version 4, the differential parity golden, maintained mixed-application and shopping-cart examples, PHP/dependency boundary packages, generated-output linting, browser protocol version 2, cache corruption safety, malformed-input/fuzz evidence, and crash-recoverable builds. Each gate has a stable identifier and an executable repository evidence reference. JSON and Markdown output are deterministic and require no network access.

PHPStan remains useful for optional deep ordinary-PHP body analysis, generator-specific flow, and reporting genuine backend infrastructure failures. It remains pinned, installed, required, and invoked by normal native `check` and `build` whenever live or exact cached supplemental evidence is needed. No public compiler-only mode exists.

Technical readiness means a separate approval can now evaluate user expectations, diagnostic trade-offs, and compatibility policy. It does not authorize silently changing the analyzer default or dependency placement.
