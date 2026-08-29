# ++PHP MVP End-to-End Development Plan

> **Repository:** `amasiye/ppphp-src`
> **Branch:** `develop`
> **Status:** Stage 4 complete; Stage 5 current
> **Last updated:** 2026-08-29

## 1. Purpose

++PHP is a PHP source compiler and language superset that adds a deliberately small set of compile-time features while continuing to target the official PHP runtime.

The MVP should prove the following product proposition:

> Write stronger, more expressive PHP-shaped source, validate it before runtime, and emit clean ordinary PHP that can be deployed like any other PHP application.

++PHP is not intended to compete directly with projects that compile PHP to C++ and native machine code. Its initial value is incremental adoption:

```text
Existing PHP project
    ↓
Add ++PHP as a development dependency
    ↓
Keep ordinary .php files unchanged
    ↓
Introduce selected .ppp files
    ↓
Compile and statically validate the project
    ↓
Deploy generated ordinary PHP
```

The MVP must be useful on its own. Future features may make the language more ambitious, but they must not delay delivery of the initial focused compiler.

---

## 2. Repository Conventions And Stage Status

The repository is organized around these compiler modules:

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

Implementation status is determined from the latest `develop` branch, not from this architectural summary. Before each stage, inspect the current repository, verify the preceding stage's acceptance criteria, and close any remaining gaps before moving forward.

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
LowerLocalDeclarationsPass
LowerWhenExpressionsPass
EraseThrowsClausesPass
```

Passes expose a common `execute()` operation. Orchestrators use role names such as `SemanticAnalyzer`, `PhpLowerer`, and `PhpStanProjectAnalyzer`.

---

## 3. Product Contract

### 3.1 Core Promise

A developer should eventually be able to run:

```bash
composer require --dev amasiye/ppphp-src
vendor/bin/ppphp init
vendor/bin/ppphp check
vendor/bin/ppphp build
```

The build output must:

```text
- Be valid PHP.
- Run on the official PHP runtime.
- Require no ++PHP runtime library unless the source explicitly imports a normal PHP package.
- Contain no ++PHP-only syntax.
- Preserve useful generic and checked-error metadata as PHPDoc.
- Be deterministic.
- Pass php -l.
```

### 3.2 Normative Semantic Rule

PHP runtime behavior remains authoritative wherever ++PHP has not explicitly added a compile-time rule or source transformation.

++PHP may:

```text
- Reject unsafe or insufficiently typed code.
- Add compile-time-only generic types.
- Enforce checked-error propagation.
- Enforce explicitly typed local declarations and readonly local bindings.
- Lower when expressions into ordinary PHP control flow.
```

++PHP must not silently redefine:

```text
- PHP object identity.
- PHP array behavior.
- PHP property mutation.
- PHP exception propagation.
- PHP truthiness or comparison semantics.
- PHP reference counting or garbage collection.
- PHP extension behavior.
```

## 4. MVP Scope

The MVP includes the following product capabilities.

### 4.1 Erased Generics

First-class generic syntax for:

```text
- Classes
- Interfaces
- Traits
- Functions
- Methods
- Generic type references
```

Generic information is checked at compile time and erased from PHP syntax. Compatible PHPDoc is emitted for PHPStan, IDEs, and ordinary PHP consumers.

There is no runtime reification, specialization, or monomorphization in the MVP.

### 4.2 Natively Typed Arrays

++PHP adds first-class generic array types:

```php
array<string> $names = [];
array<string, int> $scores = [];
readonly array<Person> $people = [];
array $phpArray = [];
```

The forms mean:

```text
array<T>       Generic list of T
array<K, V>    Generic map / associative array from K to V
array           Broad PHP-style array
```

The generic arguments are checked by ++PHP and erased from emitted PHP syntax. Compatible `list<T>` and `array<K, V>` PHPDoc is generated.

### 4.3 Strict Project-Wide Types

For `.ppp` files, the compiler enforces:

```text
- Explicit parameter, property, return, and ordinary local-variable types.
- No implicit local-variable declarations.
- No accidental implicit mixed.
- Explicit nullability.
- Compile-time argument and return checking.
- Initialized-before-use checking.
- All-path return validation.
- Unknown member diagnostics.
- Exact scalar typing beyond PHP's caller-controlled strict_types behavior.
```

Explicit broad types such as `mixed` and bare `array` remain available when the developer deliberately chooses them.

### 4.4 Explicitly Typed Local Declarations And Readonly Local Bindings

```php
string $name = "Andrew";
readonly string $creator = "Andrew";
int $attempts = 0;
?int $result = null;
```

Every ordinary local variable declaration requires an explicit type and an initializer. A local declaration is mutable by default. Prefixing it with `readonly` prevents reassignment and mutation through that local storage location.

Bare assignment never declares a variable:

```php
$attempts = 0; // Error: Assignment Cannot Declare Variable
```

There is no inferred local declaration form in the MVP.

### 4.5 `when` / `else when` / `else`

```php
string $label = when ($score >= 80) {
    return "Excellent";
} else when ($score >= 50) {
    return "Pass";
} else {
    return "Fail";
};
```

This is a value-producing ++PHP expression lowered into ordinary PHP control flow.

The MVP does not include setup clauses or control-flow `finally`.

### 4.6 Checked Errors

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

A checked error must be caught or declared in the enclosing callable's `throws` clause.

At runtime, checked errors remain ordinary PHP exceptions.

### 4.7 Mixed-Project Support

A project may contain both:

```text
.php
.ppp
```

Ordinary PHP remains unchanged. ++PHP files receive the full ++PHP language contract and are emitted as `.php` files into the configured output directory.

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
- Setup clauses or control-flow `finally`
- A new object or memory model
- Ownership or borrow checking
- Deep immutability
- Local type inference
- Per-element or per-type-argument `readonly` modifiers
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
- Automatic conversion of an entire PHP project to ++PHP
```

Features may be discussed or reserved syntactically without being added to the MVP release gate.

---

## 6. Source and Output Contract

### 6.1 Source Files

++PHP source files use:

```text
.ppp
```

A ++PHP file remains PHP-shaped and includes the normal PHP opening tag:

```php
<?php
```

An ordinary typed PHP file should be capable of being renamed from `.php` to `.ppp`, after which stricter ++PHP semantic rules may require changes.

### 6.2 Output Files

```text
src/Domain/UserService.ppp
    ↓
build/ppphp/Domain/UserService.php
```

The output must:

```text
- Preserve the namespace and relative source path.
- Contain no ++PHP-only syntax.
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
- May be enriched by ++PHP stub files.
- Remain directly executable by PHP.
```

### 6.4 Atomic Builds

`ppphp build` must be atomic:

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
ppphp init
ppphp check [path]
ppphp build [path]
ppphp clean
ppphp dump:ast <file>
ppphp --version
```

### 7.1 `init`

```text
- Creates ppphp.json when missing.
- Creates configured output, cache, and stub directories where appropriate.
- Prints required Composer autoload guidance.
- Does not silently rewrite composer.json in the MVP.
- Supports --no-interaction.
```

### 7.2 `check`

```text
- Parses selected .ppp files and relevant project-owned .php files.
- Runs ++PHP semantic checks where applicable.
- Runs the pinned PHPStan analysis backend.
- Emits no production PHP.
- Exits non-zero when errors are present.
```

### 7.3 `build`

```text
- Performs the complete check pipeline for the selected files.
- Emits selected .ppp files as production PHP only after checks succeed.
- Never rewrites or emits ordinary .php files.
- Validates generated PHP.
- Writes source maps and a build manifest.
```

### 7.4 Command Selection Semantics

++PHP does not use an entry-point configuration in the MVP. PHP applications commonly have multiple executable scripts and autoloaded declarations, so configured source roots define project ownership and the optional command path acts as a selection filter.

The final command behavior is:

```text
ppphp check
    Check the complete project under all configured source roots.

ppphp check <directory>
    Recursively check project-owned .ppp and .php files in that subtree.

ppphp check <file>
    Perform a focused check of that project-owned .ppp or .php file,
    loading required project context for resolution.

ppphp build
    Build every project-owned .ppp file under all configured source roots.

ppphp build <directory>
    Recursively build every project-owned .ppp file in that subtree.

ppphp build <file.ppp>
    Build that one focused ++PHP source file.
```

Selection rules:

```text
- Relative paths resolve from the project root.
- A selected path must remain within a configured source root.
- Directory selection respects configured exclusions.
- Plain .php files are analysis context and are never build outputs.
- A focused build emits exactly the selected .ppp files; it does not
  implicitly emit unselected or transitive ++PHP dependencies.
- Use pathless build for complete deployable project output.
- Project discovery may index unselected files and load dependencies needed
  to resolve selected targets.
- Unrelated unselected files should not block a focused command, except for
  project-global conflicts that make the selected target ambiguous or unsafe.
```

The dependency graph supports declaration resolution, analysis ordering, invalidation, and diagnostics. It is not a tree-shaking mechanism and must never reduce a pathless build below all project-owned `.ppp` files.

### 7.5 `clean`

```text
- Removes only compiler-owned output and cache files.
- Refuses to remove a path outside the project root.
- Never deletes a source directory.
```

### 7.6 `dump:ast`

A developer-oriented command that requires one explicit file and displays:

```text
- ++PHP extension nodes.
- Normalized PHP AST information.
- Source spans.
- Generated mappings.
```

---

## 8. Configuration

The initial configuration should remain small. A released configuration uses an immutable, versioned remote schema URL:

```json
{
    "$schema": "https://github.com/amasiye/ppphp-src/releases/download/<release-tag>/ppphp.schema.json",
    "source": [
        "src"
    ],
    "output": "build/ppphp",
    "cache": ".ppphp-cache",
    "targetPhpVersion": "8.4",
    "stubs": [
        "stubs"
    ],
    "exclude": [
        "vendor",
        "build",
        ".ppphp-cache"
    ]
}
```

Configuration principles:

```text
- ++PHP strictness is not a PHPStan rule level.
- .ppp has one defined language contract.
- PHPStan remains an implementation detail.
- Source, output, cache, and stub paths are relative to the project root.
- Output and cache paths may not overlap source paths.
- Unknown configuration properties produce diagnostics.
- The target PHP version is distinct from the host PHP version running the compiler.
```

### 8.1 Schema Distribution Policy

The configuration's `$schema` property is an editor and tooling hint. The compiler validates configuration through its bundled schema and implementation rules; it must not fetch the remote schema during normal `init`, `check`, `build`, or `clean` operations.

Schema references must follow these rules:

```text
- Do not reference a path under vendor/ from generated project configuration.
- Do not reference mutable develop, main, latest, or unversioned release URLs.
- ppphp init writes the immutable schema URL corresponding to the installed
  compiler release.
- Before a public website exists, publish ppphp.schema.json as an asset of
  the exact immutable GitHub release and reference that versioned asset.
- Once a public website exists, prefer a canonical versioned URL such as
  https://<public-site>/schemas/<schema-version>/ppphp.schema.json.
- The website path must remain immutable for that schema version; a separate
  convenience latest URL may exist for browsing but must not be written into
  committed project configuration.
- Development builds may use an exact commit URL or omit the instance-level
  $schema hint until an immutable schema artifact exists. They must not point
  at a mutable branch.
```

The schema document itself should declare:

```text
- Its JSON Schema dialect through its own root $schema keyword.
- A canonical, versioned $id matching the published schema identity.
```

Release automation must publish the schema artifact together with the compiler release and verify that the generated `ppphp.json` points to the matching schema version.

The existing development template's local `vendor/` schema reference is superseded by this policy. At the next stage that touches project discovery or configuration output, remove that reference. Until the first immutable schema artifact exists, development-generated configuration should omit the instance-level `$schema` property rather than point to a mutable or nonexistent URL.

PHP 8.4 is the initial compiler host and output target baseline.

---

## 9. Compiler Architecture

The compiler should use `nikic/php-parser` for ordinary PHP parsing and printing. ++PHP should own only the syntax and semantics it adds to PHP.

### 9.1 Pipeline

```text
Project configuration
        ↓
File discovery
        ↓
Source manager
        ↓
++PHP-aware tokenization
        ↓
++PHP extension syntax parsing
        ↓
Extension syntax index
        ↓
Normalization plan
        ↓
Valid analysis PHP
        ↓
PHP AST
        ↓
++PHP semantic passes
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
readonly string $name = "Andrew";
string $city = "Lusaka";
array<string> $names = [];
array<string, int> $scores = [];
class Box<T> {}
function load(): User throws Failure {}
when (...) { ... }
```

Writing a complete PHP parser solely to add these syntax families would make the MVP unnecessarily large. The frontend should therefore have two layers.

#### ++PHP Extension Layer

Responsible for:

```text
- Explicitly typed ordinary local declarations
- readonly local declaration modifiers
- Generic declarations and references
- Natively typed array forms array<T> and array<K, V>
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
- Native PHP property and class readonly syntax
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

The ++PHP semantic model owns:

```text
- Local binding mutability and declared type
- ++PHP generic parameter declarations
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
1. Check the ++PHP compiler implementation.
2. Analyse normalized PHP generated from ++PHP user projects.
```

The governing rule is:

> PHPStan is a pinned and replaceable analysis backend, not the ++PHP language specification.

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
    Checks the ++PHP compiler source.

resources/phpstan/ppphp.neon
    Checks generated analysis PHP.
```

Users must receive ++PHP diagnostics against original `.ppp` source, never generated paths or raw PHPStan implementation terminology in normal mode.

---

## 11. Feature Contracts

### 11.1 Explicitly Typed Local Declarations And Readonly Local Bindings

Supported ordinary local declaration forms:

```php
string $name = "Andrew";
readonly string $creator = "Andrew";
int $attempts = 0;
?int $result = null;
mixed $value = loadValue();
```

The grammar is conceptually:

```text
local-variable-declaration
    ::= readonly? type variable = expression ;
```

Normative rules:

```text
- Every ordinary local declaration has an explicit type.
- Every ordinary local declaration has an initializer in the MVP.
- There is no inferred mutable or inferred readonly declaration form.
- A declaration without readonly creates a mutable local binding.
- A declaration with readonly creates a readonly local binding.
- Bare assignment never declares a variable.
- Later assignment must be assignable to the fixed declared type.
- The declared type never widens because of a later assignment.
- Null is assignable only when the written type admits null.
- Explicit broad types such as mixed remain valid.
```

Examples:

```php
int $attempts = 0;
$attempts = 4;      // Valid
$attempts = null;   // Error
$attempts = "N/A";  // Error

?int $result = 0;
$result = null;     // Valid

readonly string $name = "Andrew";
$name = "Lucy";    // Error
```

A readonly local rejects every operation that can replace or mutate its stored value, including simple and compound assignment, increment/decrement, `unset`, assignment by reference, and mutation through a by-reference parameter.

For objects, readonly applies to the local binding rather than recursively freezing the referenced object:

```php
readonly User $user = new User("Andrew");

$user = new User("Lucy"); // Error
$user->name = "Lucy";      // Governed by the property's PHP/++PHP rules
```

Properties remain PHP property declarations. ++PHP does not add a second member-variable declaration model; native PHP property types, visibility, and `readonly` remain authoritative for properties.

This contract settles ordinary local declaration statements. Parameters, catch variables, `foreach` variables, destructuring targets, closure captures, globals, and static locals use their own binding positions. Their exact ++PHP declaration and mutability rules must be decided explicitly before Stage 5 reaches those constructs; PHP-style implicit binding must not be accepted accidentally.

Lowering removes the local type and local `readonly` modifier while preserving the declared type as generated PHPDoc where required:

```php
readonly Animal $animal = new Dog();
```

becomes conceptually:

```php
/** @var Animal $animal */
$animal = new Dog();
```

### 11.2 Natively Typed Arrays

++PHP supports these array types:

```text
array<T>       Generic list of T
array<K, V>    Generic map / associative array from K to V
array           Broad PHP-style array
```

Examples:

```php
array<string> $names = ["matthew", "mark", "luke", "john"];
array<string, int> $scores = ["john" => 92, "mary" => 96];
readonly array<Person> $people = [new Person("Lucas", 34)];
array $phpArray = [];
readonly array $readonlyPhpArray = [];
```

Rules:

```text
- array<T> has list semantics and every value must be assignable to T.
- array<K, V> has map/associative semantics; keys must be compatible with
  PHP's array-key domain and values must be assignable to V.
- bare array retains PHP's broad mixed list/map capability.
- Nullable forms remain explicit, for example ?array<string, int>.
- Array types may appear in local, parameter, return, property, and nested
  generic type positions.
- Typed arrays are invariant in the MVP.
- An empty array literal is assignable when it introduces no conflicting
  key or value type.
```

The exact treatment of PHP key coercion, including numeric-string keys, must follow observable PHP runtime behavior and be covered explicitly by Stage 8 tests rather than assumed.

`readonly` is a declaration modifier, not a type constructor. It may apply to the array variable or property as a whole, but not to its key type, value type, or atomic elements:

```php
readonly array<string, int> $scores = []; // Valid
array<readonly string, int> $scores = []; // Invalid
array<string, readonly int> $scores = []; // Invalid
```

A readonly array cannot be reassigned, appended to, written through an offset, unset through an offset, or passed to a mutating by-reference operation. Readonly array storage is not deep object immutability: an object contained in the array remains governed by that object's own rules.

Erasure preserves PHP's native `array` type and emits PHPDoc:

```php
array<string>             -> array with @var/@param/@return list<string>
array<string, int>        -> array with @var/@param/@return array<string, int>
array                     -> native broad array without invented element types
```

### 11.3 Strict Types

A `.ppp` file initially requires:

```text
- Typed function and method parameters
- Explicit return types
- Typed properties
- Explicitly typed ordinary local declarations
- Explicit nullable types
- Initializers for ordinary local bindings
- No undefined local variables
- No implicit mixed in ++PHP-authored declarations
- No unknown properties or methods
- No dynamic properties
- No missing returns
- No incompatible scalar coercions
- No assignment incompatible with a local binding's declared type
```

Reject in `.ppp` for the MVP:

```text
- eval
- Variable variables
- Dynamic include or require paths
- Assignment by reference
- foreach by reference
- Return by reference
- Dynamic property creation
```

Generated files still contain `declare(strict_types=1);`, but project-wide analysis is the primary guarantee. Explicit `mixed` and bare `array` remain deliberate escape hatches rather than accidental inference results.

### 11.4 Checked Errors

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
2. Calling a ++PHP callable contributes its declared error set.
3. A matching catch removes the handled type and its subtypes.
4. Remaining checked errors must be declared.
5. An override may narrow but may not widen the parent's error set.
6. Interface and abstract declarations may include throws.
7. PHP's Error hierarchy remains unchecked.
8. Runtime PHP errors and fatal conditions are outside the checked-error guarantee.
9. throws is erased into generated @throws metadata.
10. A callable without throws promises that no known checked error escapes; it does not promise PHP can never produce a Throwable.
```

Ordinary PHP signatures, PHPDoc, and ++PHP stubs enrich boundary metadata. A genuinely dynamic or unresolved callable produces an `Unchecked Call Boundary` warning rather than a false guarantee.

### 11.5 Erased Generics

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
iterable<int, User>
array<User>
array<string, User>
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

Type parameters are invariant. Native ++PHP generic syntax is authoritative over conflicting PHPDoc in `.ppp` source.

### 11.6 `when` Expressions

```php
string $label = when ($condition) {
    return $value;
} else when ($otherCondition) {
    return $otherValue;
} else {
    return $fallback;
};
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

Required MVP positions are local initializers, assignment right-hand sides, return operands, direct call arguments, and array elements.

Do not lower through closures. Use deterministic, collision-free temporary variables and ordinary PHP control flow.

---

## 12. Development Stages

| Stage | Outcome |
|---:|---|
| 0 | Repository and language contract normalized |
| 1 | Working CLI, configuration, source model, and diagnostics |
| 2 | Ordinary PHP frontend and no-op PHP build |
| 3 | Project discovery, Composer awareness, and mixed source sets |
| 4 | ++PHP extension lexer/parser and source mappings |
| 5 | Typed local declarations and readonly local bindings |
| 6 | Strict typing and PHPStan analysis backend |
| 7 | Checked errors |
| 8 | Erased generics and natively typed arrays |
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
- Register bin/ppphp.
- Add nikic/php-parser.
- Add symfony/process.
- Add test autoloading and scripts.
- Keep Laravel Prompts only if init uses it.
```

Repository cleanup:

```text
- Complete AGENTS.md and README.md.
- Complete ppphp.json.dist and phpstan.neon.dist.
- Correct phpunit.xml.
- Ignore build/ and .ppphp-cache/.
- Remove placeholder tests.
- Add resources/phpstan/ppphp.neon.
- Add resources/schema/ppphp.schema.json.
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
php bin/ppphp --version
php bin/ppphp --help
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

Parse valid PHP and build a no-op `.ppp`-to-`.php` pipeline before adding new syntax.

### Work

Implement the PHP parser adapter, ++PHP parser facade, parsed-file model, parse result, and parser interface. Retain comments and precise locations. Build a corpus covering modern PHP declarations, expressions, control flow, attributes, enums, closures, heredoc/nowdoc, interpolation, promoted properties, readonly, and nullsafe access.

### Acceptance Criteria

A `.ppp` file containing only valid PHP passes `check`, builds to valid PHP, preserves behavior and source text where required, and reports syntax errors against the original file.

---

## Stage 3 — Project Discovery and Mixed Source Sets

### Goal

Understand real Composer projects and implement the final optional-path selection model before adding ++PHP semantics.

### Work

Implement project loading, discovery, source sets, dependency graph, Composer PSR-4/classmap/file resolution, configured stubs, exclusions, output-collision detection, and deterministic command selections.

Stage 3 must distinguish:

```text
Project index
    Every project-owned .ppp and .php file under configured source roots.

Command selection
    The files targeted by the optional path supplied to check or build.

Analysis context
    Indexed declarations, stubs, vendor metadata, and dependencies needed to
    resolve the selected files.

Emission set
    Only selected .ppp files.
```

Implement these selection rules:

```text
No path:
    Select the complete project. build emits all project-owned .ppp files.

Directory path:
    Recursively select project-owned files in that subtree, respecting excludes.

File path:
    Select one project-owned file. check may focus .ppp or .php; build accepts
    only .ppp as an emission target.
```

A focused build does not automatically emit transitive or unselected ++PHP dependencies. The dependency graph supports analysis and invalidation rather than tree-shaking. Do not add an entry-point configuration to the MVP.

Apply the schema transition in Section 8.1 during this stage: remove the generated local `vendor/` schema reference. Until an immutable release schema exists, omit the instance-level `$schema` property from development-generated configuration.

Do not treat all of `vendor/` as project-owned source.

### Acceptance Criteria

Pathless, directory, and file selections behave exactly as documented; multiple source roots are handled deterministically; ++PHP and PHP files may share namespaces and call each other; ordinary PHP is never rewritten or emitted; focused builds emit only selected ++PHP files; pathless builds include every project-owned ++PHP file; output collisions are diagnosed; exclusions work; symlink loops cannot hang discovery; and development-generated configuration no longer contains a local `vendor/` schema reference.

---

## Stage 4 — ++PHP Extension Frontend

> **Implementation status:** Completed on `develop`. Syntax recognition, exact extension spans, parser-only normalization, source mapping, and inactive-stage diagnostics are implemented. Later feature semantics remain in their assigned stages.

### Goal

Parse every MVP extension syntax before implementing all semantics.

### Work

Implement tokenization, explicit typed-local parsing, local `readonly` parsing, generic declarations and references, `array<T>`, `array<K, V>`, `throws`, `when`, extension nodes, and exact original-to-normalized source mappings.

`readonly`, `when`, and `throws` are contextual where required. Ordinary PHP property/class `readonly` syntax must continue to parse through the PHP layer. Do not introduce `val` or `var` local-declaration keywords; neither is ++PHP local syntax.

### Acceptance Criteria

Strings/comments are untouched; typed locals are distinguished from properties, parameters, and expressions; local `readonly` is distinguished from native PHP readonly declarations; generic brackets and comparisons are disambiguated; `array<T>` and `array<K, V>` parse in all approved type positions; `readonly` inside a type argument is rejected precisely; every extension node has exact spans; normalization edits cannot overlap silently; and not-yet-active features produce explicit diagnostics.

---

## Stage 5 — Typed Local Declarations And Readonly Local Bindings

### Goal

Ship explicit local declarations and readonly local enforcement as the first user-visible ++PHP feature.

### Work

Implement symbol declaration, local binding checks, and local-declaration lowering. Track scopes, written types, nullability, mutable versus readonly storage, writes, compound writes, offset mutation, by-reference uses, `unset`, and closure capture.

Implement these rules:

```text
- Type Local = Initializer declares a mutable local.
- readonly Type Local = Initializer declares a readonly local.
- Every ordinary local declaration requires an explicit type and initializer.
- Bare assignment cannot declare.
- Later values must be assignable to the fixed declared type.
- Readonly local storage cannot be reassigned or mutated through that storage.
- Native PHP property declarations and property readonly behavior remain separate.
```

Before finalizing this stage, explicitly decide and document the binding syntax and mutability rules for `foreach`, destructuring, catch variables, closure captures, globals, and static locals. Do not silently inherit implicit PHP local creation.

Required diagnostics include assignment-cannot-declare, explicit-type-required, missing initializer, initializer type mismatch, later assignment type mismatch, readonly reassignment/mutation, invalid by-reference use, duplicate declaration, and use before declaration.

### Acceptance Criteria

Mutable and readonly typed declarations work; inferred declarations are rejected; bare assignment to an undeclared local fails; nullable and explicit broad types behave as written; compatible mutable assignment succeeds; incompatible assignment fails without widening; every write form to readonly storage fails; array structural mutation through a readonly local fails; object property access continues to follow property rules; shadowing is documented; captures obey the decided binding policy; generated PHP contains no local type or local readonly syntax and preserves type metadata; ordinary `.php` behavior remains unchanged.

---

## Stage 6 — Strict Types and PHPStan Adapter

### Goal

Deliver the strict whole-project type checker.

### Work

Implement the replaceable analyzer contract, PHPStan process adapter, result parsing, diagnostic mapping, analysis-PHP emitter, name resolution, and ++PHP-specific strict-type checks.

Analysis artifacts live under `.ppphp-cache/analysis/` and never appear in normal diagnostics.

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

## Stage 8 — Erased Generics And Natively Typed Arrays

### Goal

Implement a useful, constrained generic type system together with ++PHP's native list and map array forms.

### Work

Implement generic types, parameters, substitutions, generic checks, erasure, PHPDoc emission/import, arity, bounds, inheritance, shadowing, invalid runtime operations, and invariance.

Implement typed arrays as part of the same erased type system:

```text
array<T>       list<T>
array<K, V>    array<K, V>
array           broad native PHP array
```

Check list shape, map key/value assignments, nested typed arrays, nullable typed arrays, readonly array structural mutation, PHP array-key behavior, signature/property/local usage, and PHPDoc interoperability. Reject `readonly` inside generic arguments.

### Acceptance Criteria

Generic classes, interfaces, functions, nesting, arrays, and iterables work; `array<T>` behaves as a generic list; `array<K, V>` behaves as a generic map/associative array; bare `array` remains available; wrong arity and bounds fail; invalid list/map keys or values fail; runtime-dependent generic uses fail; readonly typed arrays cannot be structurally mutated; generated PHP passes PHPStan with `list<T>` or `array<K, V>` metadata; and generic metadata crosses the PHP/++PHP boundary both ways.

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
- ++PHP calling PHP
- PHP calling generated ++PHP
- PHPDoc generic PHP consumed by ++PHP
- Generated generic ++PHP consumed by PHP
- Stub-declared checked PHP boundary
- Unchecked dynamic boundary
- Shared PSR-4 prefix across output and source
```

### Acceptance Criteria

Cross-direction calls execute; Composer resolves generated classes first; ordinary PHP is not copied; stubs enrich analysis; conflicts are diagnosed; and a complete example runs from a clean checkout using documented commands only.

---

## Stage 12 — Diagnostic and Developer-Experience Polish

### Goal

Make ++PHP feel like a compiler, not a PHPStan wrapper.

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
- Typed local declarations and readonly bindings guide
- Natively typed arrays guide
- when guide
- Migration-from-PHP guide
- Example mixed application
- Changelog
- Security policy
- Versioned `ppphp.schema.json` release artifact
```

The canonical product identity is ++PHP, with the `ppphp` compiler, `.ppp` source extension, `Amasiye\Ppphp` namespace, and `amasiye/ppphp-src` Composer package.

### Final MVP Release Criteria

```text
1. Explicitly typed mutable and readonly local declarations are fully checked and erased.
2. Natively typed arrays are checked and erased with correct PHPDoc metadata.
3. Strict typing works across project files.
4. Checked errors propagate, catch, and override correctly.
5. Erased generics preserve relationships through generated PHPDoc.
6. when expressions lower predictably.
7. Mixed PHP and ++PHP calls work in both directions.
8. Generated output has no compiler runtime dependency.
9. Every generated file passes php -l.
10. All diagnostics point to original source.
11. No raw PHPStan diagnostic is exposed in normal mode.
12. Builds are atomic and deterministic.
13. Cold and warm compiler performance are recorded.
14. Cache and output operations are path-safe.
15. CI is green.
16. Documentation describes only implemented behavior.
17. A complete mixed-project example runs from a clean checkout.
18. `ppphp init` writes the matching immutable schema URL for a release, while runtime configuration validation remains network-independent.
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
├── TypedArrays/
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

Every successful build proves that output contains no ++PHP tokens, parses as the configured PHP target, derives from the same semantics as analysis output, is deterministic, and did not modify source files.

---

## 14. Dependency Policy

Recommended runtime dependencies:

```text
symfony/console
symfony/process
nikic/php-parser
phpstan/phpstan
```

Keep `laravel/prompts` only if it materially improves `ppphp init`. Non-interactive commands must not depend on prompts.

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
- given and ++PHP control-flow finally
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
- PHP-to-++PHP migration assistance
```

Native compilation remains a separate strategic discussion. ++PHP's first advantage is incremental adoption on the official PHP runtime.

---

## 16. Stage Execution Rule

The current implementation stage must be determined from the latest `develop` branch rather than hard-coded in this plan.

Before authoring or executing each stage prompt:

```text
1. Read the current repository and this plan in full.
2. Verify the preceding stage's acceptance criteria against actual code and tests.
3. Close any remaining gap before moving to the next stage.
4. Preserve later user-approved amendments recorded in this plan.
5. Do not infer missing language or CLI semantics when the plan identifies an
   open decision; obtain a decision and record it before implementation.
```

In particular, Stage 3 must implement the command-selection rules in Section 7.4, and the release/configuration work must implement the schema-distribution policy in Section 8.1.
