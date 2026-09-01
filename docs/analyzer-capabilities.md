# Analyzer Capabilities

> **Catalog version:** 1
> **Stage 13A evidence:** 33 capabilities and 33 executable parity scenarios; 22 compiler-complete, 8 compiler-partial, and 3 backend-only.

This document is the human-readable projection of `AnalysisCapabilityCatalog`. The typed catalog is authoritative. A test compares the bounded table below byte-for-byte with the catalog renderer, verifies stable ordering and unique identifiers, and confirms that every Complete or Partial claim names an executable fixture in `tests/Fixtures/AnalyzerParity/scenarios.php`.

`mvp` capabilities are required language guarantees. `boundary` capabilities are required at interoperability boundaries unless an explicit conservative contract is approved. `optional` capabilities improve lint or infrastructure behavior but do not define ++PHP correctness. `complete` means the compiler owns the recorded contract, `partial` names a deliberately bounded compiler subset, and `backend-only` means the current full native path supplies the finding.

The current compiler-only required gaps are `calls.arguments`, `calls.builtins`, `calls.members`, `calls.returns`, `flow.properties`, `interop.composer-vendor`, `interop.ordinary-php`, `interop.stubs`, `types.aliases`, and `types.assignability`. Consequently a `compilerCore` result is not full parity.

<!-- capability-catalog:start -->
| Capability ID | Name | Category | Requirement | Compiler | Supplemental | Diagnostics | Fixture evidence | Notes | Migration slice |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `calls.arguments` | Argument validation | calls-and-members | mvp | partial | complete | `P2015` | `calls-arguments` | Generic signature checks are compiler owned; general PHP argument compatibility is supplemental. | `13b-call-validation` |
| `calls.builtins` | Built-in function inference | calls-and-members | boundary | partial | complete | `P2015` | `calls-builtins` | Required collection transforms are modeled; broad built-in inference is supplemental. | `13b-builtin-models` |
| `calls.dynamic` | Dynamic invocation boundaries | calls-and-members | mvp | complete | partial | `P2023`, `P4005` | `calls-dynamic` | Genuinely dynamic invocation is classified by compiler policy. | `complete` |
| `calls.members` | Member access | calls-and-members | mvp | partial | complete | `P2018`, `P2019` | `calls-members` | Generic member types are compiler owned; general existence checks remain supplemental. | `13b-member-existence` |
| `calls.returns` | Return validation | calls-and-members | mvp | backend-only | complete | `P2016`, `P2017` | `calls-returns` | General return compatibility and path coverage are currently supplied by the backend. | `13b-return-flow` |
| `collections.list-shape` | List-shape preservation | collections | mvp | complete | partial | `P3015` | `collections-list-shape` | List-breaking operations are diagnosed by the compiler. | `complete` |
| `collections.transforms` | Collection transforms | collections | mvp | complete | partial | `P3013` | `collections-transforms` | array_filter and array_values preserve structured element flow. | `complete` |
| `collections.typed-arrays` | Typed arrays | collections | mvp | complete | partial | `P3012`, `P3013`, `P3014` | `collections-typed-arrays` | Typed array declarations, literals, and writes are compiler owned. | `complete` |
| `declarations.strict` | Strict declaration enforcement | declarations | mvp | complete | none | `P2011`, `P2012`, `P2013`, `P2033` | `declarations-strict` | ++PHP declaration policy is compiler owned. | `complete` |
| `errors.catches` | Checked-error catches | checked-errors | mvp | complete | partial | `P4012`, `P4013` | `errors-catches` | Catch validity, reachability, and ordering are compiler owned. | `complete` |
| `errors.declarations` | Checked-error declarations | checked-errors | mvp | complete | partial | `P4006`, `P4011` | `errors-declarations` | Native throws declarations and callable policies are compiler owned. | `complete` |
| `errors.override-covariance` | Checked-error override covariance | checked-errors | mvp | complete | partial | `P4004` | `errors-override-covariance` | Override error contracts are compared by the compiler. | `complete` |
| `errors.propagation` | Checked-error propagation | checked-errors | mvp | complete | partial | `P4003`, `P4008` | `errors-propagation` | Error effects propagate through compiler-resolved calls and control flow. | `complete` |
| `flow.locals` | Local variable flow | flow | mvp | complete | partial | `P2003`, `P2027`, `P2005` | `flow-locals` | Typed local declaration, initialization, mutation, and readonly flow are compiler owned. | `complete` |
| `flow.loops` | Loop binding flow | flow | mvp | complete | partial | `P2026`, `P2028` | `flow-loops` | Typed for and foreach bindings are compiler owned. | `complete` |
| `flow.properties` | Property flow | flow | mvp | partial | complete | `P2022`, `P2024` | `flow-properties` | Dynamic writes are compiler owned; deep property value flow remains supplemental. | `13b-expression-flow` |
| `flow.when` | When-expression flow | flow | mvp | complete | none | `P5002`, `P5004` | `flow-when` | When-expression control and value flow are compiler owned. | `complete` |
| `generics.arity` | Generic arity | generics | mvp | complete | partial | `P3004`, `P3006` | `generics-arity` | Native generic applications are checked by the compiler. | `complete` |
| `generics.bounds` | Generic bounds | generics | mvp | complete | partial | `P3005`, `P3011` | `generics-bounds` | Nominal applied bounds are compiler owned. | `complete` |
| `generics.declarations` | Generic declarations | generics | mvp | complete | partial | `P3002`, `P3003` | `generics-declarations` | Generic identities and scopes are compiler owned. | `complete` |
| `generics.dependent-bounds` | Dependent generic bounds | generics | mvp | complete | none | `P3005` | `generics-dependent-bounds` | Bounds are substituted left to right by the compiler. | `complete` |
| `generics.invariance` | Generic invariance | generics | mvp | complete | partial | `P3016` | `generics-invariance` | Mutable generic types are invariant under compiler policy. | `complete` |
| `generics.substitution` | Generic substitution | generics | mvp | complete | partial | `P3004` | `generics-substitution` | Applied member substitution crosses inheritance, interfaces, traits, unions, and intersections. | `complete` |
| `generics.this` | Applied generic $this | generics | mvp | complete | none | `P3005` | `generics-this` | Instance $this carries applied owner arguments and is absent from static scopes. | `complete` |
| `infrastructure.backend-failure` | Backend failure handling | infrastructure | optional | backend-only | complete | `P6005`, `P6006` | `infrastructure-backend-failure` | Backend failures are mapped to stable compiler diagnostics. | `complete` |
| `interop.composer-vendor` | Composer and vendor context | interoperability | boundary | backend-only | complete | `P6001`, `P6002` | `interop-composer-vendor` | External autoload discovery and vendor inference are supplied by native project preparation and PHPStan. | `13c-portable-dependency-index` |
| `interop.ordinary-php` | Ordinary PHP analysis | interoperability | boundary | partial | complete | `P2015`, `P2016` | `interop-ordinary-php` | Declarations participate in compiler context; deep ordinary PHP bodies remain supplemental. | `13b-ordinary-php-core` |
| `interop.stubs` | Configured stubs | interoperability | boundary | partial | complete | `P6004` | `interop-stubs` | Stub syntax is compiler context; deep stub inference remains supplemental. | `13b-stub-symbols` |
| `syntax.extension` | ++PHP extension syntax | syntax | mvp | complete | none | `P1008`, `P1009` | `syntax-extension` | Extension syntax is parsed and normalized before semantic analysis. | `complete` |
| `syntax.php` | PHP syntax parsing | syntax | mvp | complete | none | `P1001` | `syntax-php` | PHP grammar is parsed in process. | `complete` |
| `types.aliases` | Type aliases and names | type-system | mvp | partial | partial | `P2020` | `types-aliases` | Compiler-owned source names are resolved; external names remain supplemental. | `13b-external-type-resolution` |
| `types.assignability` | General assignability | type-system | mvp | partial | complete | `P2008`, `P2009`, `P2025` | `types-assignability` | Bindings are compiler owned; arbitrary PHP expression flow is not complete. | `13b-expression-flow` |
| `types.composites` | Composite types | type-system | mvp | complete | partial | `P2030`, `P2031`, `P2032` | `types-composites` | Union and intersection validity and compiler-owned assignability are implemented. | `complete` |
<!-- capability-catalog:end -->

Run `composer verify:analyzer-parity` to compare compiler-owned and full native results with the reviewed golden at `tests/Golden/Analysis/analyzer-parity.json`. Intentional changes require `UPDATE_ANALYZER_PARITY=1`; review the diff before committing it.
