# PHPlus MVP End-to-End Development Plan

> **Working name:** PHPlus  
> **Repository:** `amasiye/phplus-src`  
> **Branch:** `develop`  
> **Status:** Proposed execution plan  
> **Last updated:** 2026-08-27

## 1. Purpose

PHPlus is a working name for a PHP source compiler and language superset that adds a deliberately small set of compile-time features while continuing to target the official PHP runtime.

The MVP should prove the following product proposition:

> Write stronger, more expressive PHP-shaped source, validate it before runtime, and emit clean ordinary PHP that can be deployed like any other PHP application.

PHPlus is not intended to compete directly with projects that compile PHP to C++ and native machine code. Its initial value is incremental adoption:

```text
Existing PHP project
    ↓
Add PHPlus as a development dependency
    ↓
Keep ordinary .php files unchanged
    ↓
Introduce selected .phplus files
    ↓
Compile and statically validate the project
    ↓
Deploy generated ordinary PHP
```

The MVP must be useful on its own. Future features may make the language more ambitious, but they must not delay delivery of the initial focused compiler.

---

## 2. Current Repository State

The `develop` branch already contains the agreed top-level compiler modules and initial class stubs:

```text
src/
├── Cli/
├── Compiler/
├── Config/
├── Diagnostics/
├── Frontend/
├── Interop/
├── Project/
├── Semantic/
├── Source/
├── Support/
└── Transpilation/
```

The repository also already includes Symfony Console, Laravel Prompts, PHPStan, Pest, a Composer autoloader, a `bin/phplus` entry point, and initial semantic and transpilation pass names.

Most classes are currently scaffolds. Stage 0 must normalize the foundation before language features are implemented.

The accepted organization and naming conventions are:

```text
- Concrete classes live directly in their owning module or subdomain.
- Interfaces live under Interfaces/.
- Enumerations live under Enumerations/.
- Traits live under Traits/.
- Attributes live under Attributes/.
- Exceptions live under Exceptions/.
- Abstract classes live under AbstractClasses/.
- Do not create Classes/ directories.
```

Pipeline pass classes use:

```text
<Verb><Object>Pass
```

Examples:

```text
DeclareSymbolsPass
ResolveNamesPass
CheckTypesPass
CheckBindingsPass
CheckGenericTypesPass
CheckErrorEffectsPass
EraseGenericTypesPass
LowerBindingsPass
LowerWhenExpressionsPass
EraseThrowsClausesPass
```

Passes expose a common `execute()` operation. Orchestrators use role names such as `SemanticAnalyzer`, `PhpLowerer`, and `PhpStanProjectAnalyzer`.

---

## 3. Product Contract

### 3.1 Core Promise

A developer should eventually be able to run:

```bash
composer require --dev amasiye/phplus-src
vendor/bin/phplus init
vendor/bin/phplus check
vendor/bin/phplus build
```

The build output must:

```text
- Be valid PHP.
- Run on the official PHP runtime.
- Require no PHPlus runtime library unless the source explicitly imports a normal PHP package.
- Contain no PHPlus-only syntax.
- Preserve useful generic and checked-error metadata as PHPDoc.
- Be deterministic.
- Pass php -l.
```

### 3.2 Normative Semantic Rule

PHP runtime behavior remains authoritative wherever PHPlus has not explicitly added a compile-time rule or source transformation.

PHPlus may:

```text
- Reject unsafe or insufficiently typed code.
- Add compile-time-only generic types.
- Enforce checked-error propagation.
- Distinguish immutable and mutable local bindings.
- Lower when expressions into ordinary PHP control flow.
```

PHPlus must not silently redefine:

```text
- PHP object identity.
- PHP array behavior.
- PHP property mutation.
- PHP exception propagation.
- PHP truthiness or comparison semantics.
- PHP reference counting or garbage collection.
- PHP extension behavior.
```

### 3.3 Relationship to Doria

Doria and PHPlus are separate languages with different contracts.

```text
Doria:
    Native-first language with its own semantics and PHP as one backend.

PHPlus:
    PHP-runtime-first language superset whose output is ordinary PHP.
```

The projects may share language-design knowledge, diagnostic conventions, algorithms, and eventually selected infrastructure, but PHPlus must not be lowered through Doria's semantic model.

---

## 4. MVP Scope

The MVP contains exactly six product capabilities.

### 4.1 Erased Generics

First-class generic syntax for:

```text
- Classes
- Interfaces
- Traits
- Functions
- Methods
- Generic type references
- Common array/list/iterable compile-time forms
```

Generic information is checked at compile time and erased from PHP syntax. Compatible PHPDoc is emitted for PHPStan, IDEs, and ordinary PHP consumers.

There is no runtime reification, specialization, or monomorphization in the MVP.

### 4.2 Strict Project-Wide Types

For `.phplus` files, the compiler enforces:

```text
- Explicit parameter, property, and return types.
- Inferred local types through val and var.
- No accidental implicit mixed.
- Explicit nullability.
- Compile-time argument and return checking.
- Initialized-before-use checking.
- All-path return validation.
- Unknown member diagnostics.
- Exact scalar typing beyond PHP's caller-controlled strict_types behavior.
```

### 4.3 `val` and `var`

```php
val $name = "Andrew";
var int $attempts = 0;
```

`val` creates a local binding that cannot be rebound.

`var` creates a local binding that may be rebound, provided later values remain type-compatible.

Both lower to ordinary PHP assignments.

### 4.4 `when` / `else when` / `else`

```php
val $label = when ($score >= 80) {
    return "Excellent";
} else when ($score >= 50) {
    return "Pass";
} else {
    return "Fail";
};
```

This is a value-producing PHPlus expression lowered into ordinary PHP control flow.

The MVP does not include Doria's `given`, setup clauses, or control-flow `finally`.

### 4.5 Checked Errors

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

A checked error must be caught or declared in the enclosing callable's `throws` clause.

At runtime, checked errors remain ordinary PHP exceptions.

### 4.6 Mixed-Project Support

A project may contain both:

```text
.php
.phplus
```

Ordinary PHP remains unchanged. PHPlus files receive the full PHPlus language contract and are emitted as `.php` files into the configured output directory.

---

## 5. Explicit MVP Non-Goals

```text
- PHP-to-C++ or PHP-to-native compilation
- A custom runtime or virtual machine
- Reified generics
- Generic specialization or monomorphization
- Runtime generic reflection
- Explicit generic call-site arguments
- Variance annotations
- Generic defaults
- Higher-kinded types
- Macros
- Async/await
- Pattern matching
- Doria-style given or control-flow finally
- A new object or memory model
- Ownership or borrow checking
- Deep immutability
- Automatic runtime boundary guards
- Framework-specific compiler plugins
- Laravel, Symfony, or Doctrine magic emulation
- An LSP
- A formatter
- A Composer plugin
- PHAR packaging
- Native launcher packaging
- Native-performance claims
- Support for every historical PHP version
- Automatic conversion of an entire PHP project to PHPlus
```

Features may be discussed or reserved syntactically without being added to the MVP release gate.

---

## 6. Source and Output Contract

### 6.1 Source Files

PHPlus source files use:

```text
.phplus
```

A PHPlus file remains PHP-shaped and includes the normal PHP opening tag:

```php
<?php
```

An ordinary typed PHP file should be capable of being renamed from `.php` to `.phplus`, after which stricter PHPlus semantic rules may require changes.

### 6.2 Output Files

```text
src/Domain/UserService.phplus
    ↓
build/phplus/Domain/UserService.php
```

The output must:

```text
- Preserve the namespace and relative source path.
- Contain no PHPlus-only syntax.
- Contain declare(strict_types=1).
- Preserve useful source comments and descriptive PHPDoc.
- Add deterministic generated PHPDoc where required.
- Pass php -l.
- Require no compiler runtime.
```

### 6.3 Plain PHP Files

Plain `.php` files:

```text
- Are never rewritten by default.
- Are available for symbol and type analysis.
- May contribute existing PHPDoc metadata.
- May be enriched by PHPlus stub files.
- Remain directly executable by PHP.
```

### 6.4 Atomic Builds

`phplus build` must be atomic:

```text
1. Validate the project.
2. Compile into a temporary output tree.
3. Validate every generated PHP file.
4. Replace the previous successful output only after all files succeed.
5. Keep the previous successful output when compilation fails.
```

A failed build must never leave a partially updated application.

---

## 7. CLI Surface

The MVP command surface should be:

```text
phplus init
phplus check [path]
phplus build [path]
phplus clean
phplus dump:ast <file>
phplus --version
```

### 7.1 `init`

```text
- Creates phplus.json when missing.
- Creates configured output, cache, and stub directories where appropriate.
- Prints required Composer autoload guidance.
- Does not silently rewrite composer.json in the MVP.
- Supports --no-interaction.
```

### 7.2 `check`

```text
- Parses .phplus and relevant .php files.
- Runs PHPlus semantic checks.
- Runs the pinned PHPStan analysis backend.
- Emits no production PHP.
- Exits non-zero when errors are present.
```

### 7.3 `build`

```text
- Performs the complete check pipeline.
- Emits production PHP only after all checks succeed.
- Validates generated PHP.
- Writes source maps and a build manifest.
```

### 7.4 `clean`

```text
- Removes only compiler-owned output and cache files.
- Refuses to remove a path outside the project root.
- Never deletes a source directory.
```

### 7.5 `dump:ast`

A developer-oriented command that displays:

```text
- PHPlus extension nodes.
- Normalized PHP AST information.
- Source spans.
- Generated mappings.
```

---

## 8. Configuration

The initial configuration should remain small:

```json
{
    "$schema": "./vendor/amasiye/phplus-src/resources/schema/phplus.schema.json",
    "source": [
        "src"
    ],
    "output": "build/phplus",
    "cache": ".phplus-cache",
    "targetPhpVersion": "8.4",
    "stubs": [
        "stubs"
    ],
    "exclude": [
        "vendor",
        "build",
        ".phplus-cache"
    ]
}
```

Configuration principles:

```text
- PHPlus strictness is not a PHPStan rule level.
- .phplus has one defined language contract.
- PHPStan remains an implementation detail.
- Source, output, cache, and stub paths are relative to the project root.
- Output and cache paths may not overlap source paths.
- Unknown configuration properties produce diagnostics.
- The target PHP version is distinct from the host PHP version running the compiler.
```

Stage 0 must explicitly select and encode the minimum compiler host PHP version. PHP 8.4 is the recommended initial baseline, subject to final confirmation against dependencies and release goals.

---

## 9. Compiler Architecture

The compiler should use `nikic/php-parser` for ordinary PHP parsing and printing. PHPlus should own only the syntax and semantics it adds to PHP.

### 9.1 Pipeline

```text
Project configuration
        ↓
File discovery
        ↓
Source manager
        ↓
PHPlus-aware tokenization
        ↓
PHPlus extension syntax parsing
        ↓
Extension syntax index
        ↓
Normalization plan
        ↓
Valid analysis PHP
        ↓
PHP AST
        ↓
PHPlus semantic passes
        ↓
Analysis-PHP emission
        ↓
Pinned PHPStan analysis
        ↓
Diagnostic mapping
        ↓
Production lowering passes
        ↓
Production PHP emission
        ↓
php -l validation
        ↓
Build manifest and source maps
```

### 9.2 Two-Layer Frontend

A standard PHP parser cannot directly parse:

```php
val $x = 1;
class Box<T> {}
function load(): User throws Failure {}
when (...) { ... }
```

Writing a complete PHP parser solely to add these syntax families would make the MVP unnecessarily large. The frontend should therefore have two layers.

#### PHPlus Extension Layer

Responsible for:

```text
- val and var
- Generic declarations and references
- throws clauses
- when expressions
- Exact original source spans
- Source-to-normalized mappings
```

#### PHP Parser Layer

Responsible for:

```text
- Ordinary declarations
- Expressions and statements
- Namespaces and imports
- Classes, interfaces, traits, and enums
- Attributes
- Closures
- PHP control flow
- Comments and ordinary PHP parse errors
```

### 9.3 No Regex Architecture

Regular expressions must not drive source transformation.

The extension frontend must be:

```text
- Token-aware
- Nesting-aware
- Comment-aware
- String-aware
- Interpolation-aware
- Heredoc/nowdoc-aware
- Context-aware
```

### 9.4 Semantic Model Boundary

The PHPlus semantic model owns:

```text
- Binding kind
- PHPlus generic parameter declarations
- Generic source types
- Checked-error declarations and effects
- when branch structure
- Original source spans
- Lowering metadata
- Imported PHP boundary metadata
```

PHPStan initially owns:

```text
- Whole-project PHP symbol resolution
- Flow-sensitive PHP type checking
- PHP argument and return checking
- PHPDoc generic substitution
- PHP inheritance rules
- Much of try/catch flow analysis
```

---

## 10. PHPStan Integration Contract

PHPStan is used in two independent roles:

```text
1. Check the PHPlus compiler implementation.
2. Analyse normalized PHP generated from PHPlus user projects.
```

The governing rule is:

> PHPStan is a pinned and replaceable analysis backend, not the PHPlus language specification.

Use:

```text
Analysis/Interfaces/ProjectAnalyzer
Analysis/PhpStan/PhpStanProjectAnalyzer
Analysis/PhpStan/PhpStanProcessRunner
Analysis/PhpStan/PhpStanResultParser
Analysis/PhpStan/PhpStanDiagnosticMapper
```

Maintain separate configurations:

```text
phpstan.neon.dist
    Checks the PHPlus compiler source.

resources/phpstan/phplus.neon
    Checks generated analysis PHP.
```

Users must receive PHPlus diagnostics against original `.phplus` source, never generated paths or raw PHPStan implementation terminology in normal mode.

---

## 11. Feature Contracts

### 11.1 `val` and `var`

Supported forms:

```php
val $name = "Andrew";
var $count = 0;

val User $user = loadUser($id);
var ?User $selected = null;
```

An initializer is mandatory in the MVP.

A `val` binding may not be:

```text
- Directly reassigned
- Incremented or decremented
- Used with compound assignment
- Unset
- Assigned by reference
- Captured by reference
- Passed to a by-reference parameter when the call may mutate it
```

`val` is binding immutability, not recursive object immutability:

```php
val $user = new User();

$user->name = "Andrew"; // Allowed when PHP property rules permit it.
```

For PHP arrays, the MVP treats offset mutation as mutation through the bound container rather than rebinding:

```php
val $items = [];

$items[] = "one"; // Allowed in the MVP.
$items = [];       // Error.
```

Function and method parameters are immutable bindings by default. Writable parameters are a future feature unless separately approved.

### 11.2 Strict Types

A `.phplus` file initially requires:

```text
- Typed function and method parameters
- Explicit return types
- Typed properties
- Explicit nullable types
- Initializers for local bindings
- No undefined local variables
- No implicit mixed in PHPlus-authored declarations
- No unknown properties or methods
- No dynamic properties
- No missing returns
- No incompatible scalar coercions
- No assignment incompatible with a local binding's type
```

Reject in `.phplus` for the MVP:

```text
- eval
- Variable variables
- Dynamic include or require paths
- Assignment by reference
- foreach by reference
- Return by reference
- Dynamic property creation
```

Generated files still contain `declare(strict_types=1);`, but project-wide analysis is the primary guarantee.

### 11.3 Checked Errors

Recommended grammar:

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

Core rules:

```text
1. A directly thrown exception contributes its static type to the callable's error set.
2. Calling a PHPlus callable contributes its declared error set.
3. A matching catch removes the handled type and its subtypes.
4. Remaining checked errors must be declared.
5. An override may narrow but may not widen the parent's error set.
6. Interface and abstract declarations may include throws.
7. PHP's Error hierarchy remains unchecked.
8. Runtime PHP errors and fatal conditions are outside the checked-error guarantee.
9. throws is erased into generated @throws metadata.
10. A callable without throws promises that no known checked error escapes; it does not promise PHP can never produce a Throwable.
```

Ordinary PHP signatures, PHPDoc, and PHPlus stubs enrich boundary metadata. A genuinely dynamic or unresolved callable produces an `Unchecked Call Boundary` warning rather than a false guarantee.

### 11.4 Erased Generics

Support generic classes, interfaces, traits, functions, and methods:

```php
class Box<T> {}
interface Repository<T> {}
trait Stores<T> {}

function identity<T>(T $value): T
{
    return $value;
}
```

Support a single upper bound:

```php
class Repository<T : Entity> {}
```

Support type uses such as:

```php
Repository<User>
Box<string>
array<User>
array<string, User>
list<User>
iterable<int, User>
```

Emit compatible `@template`, `@extends`, `@implements`, `@use`, `@param`, `@return`, and `@var` metadata.

Reject:

```text
- new T()
- T::class
- instanceof T
- Static storage using class-level type parameters
- Explicit call-site type arguments
- Generic anonymous classes
- Runtime reification
- Specialization
- Variance
- Generic defaults
```

Type parameters are invariant. Native PHPlus generic syntax is authoritative over conflicting PHPDoc in `.phplus` source.

### 11.5 `when` Expressions

```php
when ($condition) {
    return $value;
} else when ($otherCondition) {
    return $otherValue;
} else {
    return $fallback;
}
```

Rules:

```text
- when is value-producing.
- A final else is mandatory.
- Every reachable branch yields or terminates.
- return yields from when, not the enclosing function.
- A throw branch has type never.
- Branch bindings stay branch-local.
- Conditions evaluate left-to-right and at most once.
- break and continue are rejected inside value-producing branches.
```

Required MVP positions are binding initializers, assignment right-hand sides, return operands, direct call arguments, and array/list elements.

Do not lower through closures. Use deterministic, collision-free temporary variables and ordinary PHP control flow.

---

## 12. Development Stages

| Stage | Outcome |
|---:|---|
| 0 | Repository and language contract normalized |
| 1 | Working CLI, configuration, source model, and diagnostics |
| 2 | Ordinary PHP frontend and no-op PHP build |
| 3 | Project discovery, Composer awareness, and mixed source sets |
| 4 | PHPlus extension lexer/parser and source mappings |
| 5 | `val` and `var` bindings |
| 6 | Strict typing and PHPStan analysis backend |
| 7 | Checked errors |
| 8 | Erased generics |
| 9 | `when` expressions |
| 10 | Production emission, manifests, and atomic builds |
| 11 | Full mixed-project and interoperability validation |
| 12 | Diagnostic and developer-experience polish |
| 13 | Incremental performance, security, and hardening |
| 14 | Public MVP release |

Stages are completed in order. A later stage must not excuse an incomplete earlier acceptance criterion.

---

## Stage 0 — Normalize the Foundation

### Goal

Turn the current scaffold into a reliable, documented PHP compiler project without implementing language features.

### Work

Update Composer metadata:

```text
- Add an explicit host PHP requirement.
- Change package type from project to library.
- Register bin/phplus.
- Add nikic/php-parser.
- Add symfony/process.
- Add test autoloading and scripts.
- Keep Laravel Prompts only if init uses it.
```

Repository cleanup:

```text
- Complete AGENTS.md and README.md.
- Complete phplus.json.dist and phpstan.neon.dist.
- Correct phpunit.xml.
- Ignore build/ and .phplus-cache/.
- Remove placeholder tests.
- Add resources/phpstan/phplus.neon.
- Add resources/schema/phplus.schema.json.
- Add core design documentation.
- Add CI.
```

Naming alignment:

```text
ThrowClause.php → ThrowsClause.php
CheckErrorsPass.php → CheckErrorEffectsPass.php
CheckGenericsPass.php → CheckGenericTypesPass.php
Analyzer.php → SemanticAnalyzer.php
```

Do not add empty future-oriented classes merely to match an architectural sketch.

### Acceptance Criteria

```bash
composer validate --strict
composer install
composer analyse
composer test
php bin/phplus --version
php bin/phplus --help
```

All succeed. No language feature is implemented.

---

## Stage 1 — CLI, Configuration, Source Model, and Diagnostics

### Goal

Create the operational shell every later feature uses.

### Work

Implement the application and commands, project config loading, source files and spans, structured diagnostics, console and JSON renderers, stable diagnostic ranges, stable exit codes, and guarded internal-error handling.

Diagnostic families:

```text
P0xxx   Configuration and project errors
P1xxx   Lexing and syntax
P2xxx   Bindings and strict types
P3xxx   Generic types
P4xxx   Checked errors
P5xxx   when expressions
P6xxx   PHP and Composer interoperability
P7xxx   Emission and generated PHP
P9xxx   Internal compiler errors
```

Exit codes:

```text
0   Success
1   Source diagnostics
2   Invalid project or configuration
3   Output validation failure
70  Internal compiler failure
```

### Acceptance Criteria

`init` creates valid config; invalid JSON and unknown properties produce source-located diagnostics; unsafe paths are rejected; console and JSON represent the same diagnostic; CRLF and multibyte spans work; and `clean` refuses unsafe paths.

---

## Stage 2 — Ordinary PHP Frontend

### Goal

Parse valid PHP and build a no-op `.phplus`-to-`.php` pipeline before adding new syntax.

### Work

Implement the PHP parser adapter, PHPlus parser facade, parsed-file model, parse result, and parser interface. Retain comments and precise locations. Build a corpus covering modern PHP declarations, expressions, control flow, attributes, enums, closures, heredoc/nowdoc, interpolation, promoted properties, readonly, and nullsafe access.

### Acceptance Criteria

A `.phplus` file containing only valid PHP passes `check`, builds to valid PHP, preserves behavior and source text where required, and reports syntax errors against the original file.

---

## Stage 3 — Project Discovery and Mixed Source Sets

### Goal

Understand real Composer projects before adding PHPlus semantics.

### Work

Implement project loading, discovery, source sets, dependency graph, Composer PSR-4/classmap/file resolution, configured stubs, exclusions, and output-collision detection. Do not treat all of `vendor/` as project-owned source.

### Acceptance Criteria

PHPlus and PHP may share namespaces and call each other; ordinary PHP is not rewritten; output collisions are diagnosed; exclusions work; and symlink loops cannot hang discovery.

---

## Stage 4 — PHPlus Extension Frontend

### Goal

Parse every MVP extension syntax before implementing all semantics.

### Work

Implement tokenization, contextual keywords, extension nodes, feature-specific syntax parsers, and exact original-to-normalized source mappings.

`val`, `var`, `when`, and `throws` remain identifiers where their feature grammar is not expected.

### Acceptance Criteria

Strings/comments are untouched; generic brackets and comparisons are disambiguated; class-member `var` is not a local binding; every extension node has exact spans; normalization edits cannot overlap silently; and not-yet-active features produce explicit diagnostics.

---

## Stage 5 — `val` and `var`

### Goal

Ship the first user-visible feature and prove the architecture.

### Work

Implement symbol declaration, binding checks, and binding lowering. Track scopes, binding kinds, types, writes, by-reference uses, `unset`, and closure capture.

Required diagnostics include immutable reassignment, undeclared binding, missing initializer, invalid by-reference use, duplicate declaration, use before declaration, and legacy class-property `var` use.

### Acceptance Criteria

All binding forms work; `val` writes fail; compatible `var` writes succeed; shadowing is documented; captures obey mutability; generated PHP contains no PHPlus binding syntax; and ordinary PHP behavior remains unchanged.

---

## Stage 6 — Strict Types and PHPStan Adapter

### Goal

Deliver the strict whole-project type checker.

### Work

Implement the replaceable analyzer contract, PHPStan process adapter, result parsing, diagnostic mapping, analysis-PHP emitter, name resolution, and PHPlus-specific strict-type checks.

Analysis artifacts live under `.phplus-cache/analysis/` and never appear in normal diagnostics.

### Acceptance Criteria

Argument, return, missing return, member, nullability, and implicit-`mixed` failures are detected; ordinary PHP contributes native/PHPDoc types; PHPStan crashes and timeouts become structured diagnostics; and the compiler's own source passes its separate PHPStan configuration.

---

## Stage 7 — Checked Errors

### Goal

Add first-class checked error declarations without changing runtime propagation.

### Work

Implement error sets, effect resolution, effect compatibility, the checked-error pass, throws-clause erasure, and PHPDoc throws import.

```text
Direct throws
+ Called error sets
- Caught errors
= Escaping checked errors
```

### Acceptance Criteria

Direct, nested, caught, partially caught, constructor, interface, abstract, and override cases work; PHP and stub metadata are imported; generated PHP contains correct `@throws`; and runtime behavior remains ordinary PHP.

---

## Stage 8 — Erased Generics

### Goal

Implement a useful, constrained generic type system.

### Work

Implement generic types, parameters, substitutions, generic checks, erasure, PHPDoc emission/import, arity, bounds, inheritance, shadowing, invalid runtime operations, and invariance.

### Acceptance Criteria

Generic classes, interfaces, functions, nesting, arrays, lists, and iterables work; wrong arity and bounds fail; runtime-dependent uses fail; generated PHP passes PHPStan; and metadata crosses the PHP/PHPlus boundary both ways.

---

## Stage 9 — `when` Expressions

### Goal

Deliver expression-oriented conditional flow with predictable lowering.

### Work

Implement semantic checks, lowering, deterministic temporary names, and a lowering result capable of carrying prerequisite statements before the value expression.

### Acceptance Criteria

All required source positions work; nested and throwing branches work; missing `else`, missing values, and result-type mismatches fail; conditions evaluate once and in order; and generated PHP uses no synthetic closures.

---

## Stage 10 — Production Emission and Atomic Builds

### Goal

Turn successful analyses into clean deployment output.

### Work

Implement the compiler pipeline, PHP lowerer, contexts, production emitter, printer, source maps, manifests, atomic replacement, deterministic PHPDoc, stale-output cleanup, and `php -l` validation.

### Acceptance Criteria

Every output file parses; failed builds preserve prior output; repeated builds are byte-identical; stale output is removed safely; output cannot escape the build root; manifests are complete; and execution tests match expected output and status.

---

## Stage 11 — Full Mixed-Project Validation

### Goal

Prove realistic adoption workflows.

### Fixtures

```text
- PHPlus calling PHP
- PHP calling generated PHPlus
- PHPDoc generic PHP consumed by PHPlus
- Generated generic PHPlus consumed by PHP
- Stub-declared checked PHP boundary
- Unchecked dynamic boundary
- Shared PSR-4 prefix across output and source
```

### Acceptance Criteria

Cross-direction calls execute; Composer resolves generated classes first; ordinary PHP is not copied; stubs enrich analysis; conflicts are diagnosed; and a complete example runs from a clean checkout using documented commands only.

---

## Stage 12 — Diagnostic and Developer-Experience Polish

### Goal

Make PHPlus feel like a compiler, not a PHPStan wrapper.

Every diagnostic contains a stable code, Title Case summary, original path, primary span, related span where useful, explanation, and concrete help.

### Acceptance Criteria

Golden tests cover every diagnostic family; generated paths and analyzer/parser implementation terminology never appear in normal output; cascades are suppressed; JSON output is stable; `--debug` reveals internals; color honors `NO_COLOR`; and non-interactive environments never prompt.

---

## Stage 13 — Incremental Performance, Security, and Hardening

### Goal

Make repeated use practical and eliminate obvious hazards.

Cache keys include source/config/compiler/target/PHPStan/stub/Composer-lock hashes. Reuse normalized source, token streams, safe parsed artifacts, source maps, and PHPStan's result cache.

Record cold/warm check and build time, peak memory, and output size against small, medium, and large fixture projects. Measurements inform development but must not become fragile platform-specific blockers.

Security rules:

```text
- Never evaluate user source.
- Never use eval.
- Do not execute arbitrary analyzer bootstrap files automatically.
- Validate subprocess arguments and apply timeouts.
- Prevent path traversal and unsafe symlink traversal.
- Restrict output/cache paths to the project root by default.
- clean removes manifest-owned files only.
- Do not expose environment secrets.
- Validate output before treating it as successful.
```

Add malformed-source, fuzz-smoke, interrupted-build, read-only-filesystem, invalid-UTF-8, very-long-line, deep-nesting, Windows-path, and CRLF tests.

### Acceptance Criteria

Warm builds reuse work; cache corruption rebuilds safely; interrupted builds preserve prior output; `clean` is path-safe; deterministic builds remain deterministic; dependency scanning is enabled; and malformed input does not crash the compiler.

---

## Stage 14 — MVP Release

### Goal

Publish a credible first release usable in a real PHP project.

Required documentation:

```text
- Complete README
- Language guide
- Compiler architecture guide
- Mixed-project guide
- Erased-generics guide
- Checked-errors guide
- val/var guide
- when guide
- Migration-from-PHP guide
- Example mixed application
- Changelog
- Security policy
```

Before a stable public identity, decide the final product name, Composer package, CLI executable, source extension, namespace, and public documentation references. The unresolved working name must not block implementation.

### Final MVP Release Criteria

```text
1. val and var are fully checked and erased.
2. Strict typing works across project files.
3. Checked errors propagate, catch, and override correctly.
4. Erased generics preserve relationships through generated PHPDoc.
5. when expressions lower predictably.
6. Mixed PHP and PHPlus calls work in both directions.
7. Generated output has no compiler runtime dependency.
8. Every generated file passes php -l.
9. All diagnostics point to original source.
10. No raw PHPStan diagnostic is exposed in normal mode.
11. Builds are atomic and deterministic.
12. Cold and warm compiler performance are recorded.
13. Cache and output operations are path-safe.
14. CI is green.
15. Documentation describes only implemented behavior.
16. A complete mixed-project example runs from a clean checkout.
```

---

## 13. Testing Strategy

Every feature stage adds relevant unit, fixture, golden, integration, and execution tests.

```text
tests/Fixtures/
├── Parsing/
├── Bindings/
├── StrictTypes/
├── CheckedErrors/
├── Generics/
├── When/
├── MixedProjects/
└── InvalidProjects/

tests/Golden/
├── Diagnostics/
├── AnalysisPhp/
├── ProductionPhp/
└── SourceMaps/
```

For successful generated programs:

```text
1. Build.
2. Run php -l.
3. Execute with PHP.
4. Compare stdout.
5. Compare stderr.
6. Compare exit status.
```

Every successful build proves that output contains no PHPlus tokens, parses as the configured PHP target, derives from the same semantics as analysis output, is deterministic, and did not modify source files.

---

## 14. Dependency Policy

Recommended runtime dependencies:

```text
symfony/console
symfony/process
nikic/php-parser
phpstan/phpstan
```

Keep `laravel/prompts` only if it materially improves `phplus init`. Non-interactive commands must not depend on prompts.

Development dependency:

```text
pestphp/pest
```

Avoid a DI container, full framework, database, general event bus, second AST library, parser generator for the MVP, or snapshot package before custom golden helpers prove insufficient.

---

## 15. Post-MVP Roadmap

Possible future features after real-world use:

```text
- Generic variance and defaults
- Explicit generic call arguments
- Opt-in reified generics
- More when expression positions
- Statement-form when
- given and PHPlus control-flow finally
- Typed array shapes
- Type aliases
- Sealed classes
- Exhaustive enum handling
- Result-style error APIs
- Checked/unchecked boundary attributes
- Runtime boundary guards
- Formatter
- LSP and IDE plugins
- Watch mode
- Composer plugin
- PHAR distribution
- PHP-to-PHPlus migration assistance
- Optional Doria interoperability
```

Native compilation remains a separate strategic discussion. PHPlus's first advantage is incremental adoption on the official PHP runtime.

---

## 16. Immediate Next Implementation Unit

The first engineering task is **Stage 0 only**.

It must not implement `val`, generics, checked errors, or `when`.

Definition of done:

```text
- Composer metadata corrected.
- Host PHP baseline selected.
- nikic/php-parser and symfony/process added.
- Composer bin registered.
- README and AGENTS completed.
- Configuration and PHPStan templates completed.
- phpunit.xml corrected.
- build and cache directories ignored.
- Naming aligned with the accepted tree.
- Analysis module scaffolded only where immediately needed.
- CI added.
- composer validate, analyse, test, and CLI help all pass.
```

Once Stage 0 lands, Stage 1 can build the operational compiler infrastructure without carrying placeholder debt forward.
