# Analyzer Promotion Readiness

Stage 13D established that compiler-owned analysis is technically ready for a possible future native-default switch.

The deterministic `composer verify:analyzer-promotion` report currently records:

- technical gates: **Pass**;
- MVP decision: **Retain supplemental analysis**;
- future default change: **Not approved**;
- native default: **PHPStan supplemental path**; and

The gates cover every required and boundary capability in catalog version 4, the differential parity golden, maintained mixed-application and shopping-cart examples, PHP/dependency boundary packages, generated-output linting, browser protocol version 2, cache corruption safety, malformed-input/fuzz evidence, and crash-recoverable builds. Each gate has a stable identifier and an executable repository evidence reference. JSON and Markdown output are deterministic and require no network access.

[ADR 0004](decisions/0004-mvp-native-analysis-retains-phpstan.md) makes the MVP product decision. PHPStan remains useful for optional deep ordinary-PHP body analysis, generator-specific flow, and reporting genuine backend infrastructure failures. It remains pinned, installed, required, and invoked by normal native `check` and `build` whenever live or exact cached supplemental evidence is needed. No public compiler-only mode exists.

Technical readiness does not approve a future switch or dependency move. Making PHPStan optional or changing the native default remains a separate post-MVP product and packaging decision.
