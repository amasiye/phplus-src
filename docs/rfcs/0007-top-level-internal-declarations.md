# RFC 0007 — Top-Level Internal Declarations

```text
Status: Draft
Implementation: Proposed For Stage 16
```

This RFC proposes an `internal` modifier for top-level declarations while
preserving PHP's existing `namespace` and `use` model.

The selected direction is intentionally smaller than a new module/import/export
system:

```php
namespace App\Orders;

internal final class OrderHydrator
{
}

final class OrderService
{
}
```

The feature addresses PHP's lack of enforceable top-level implementation
visibility. It does not introduce a runtime module loader, new import syntax, or
another symbol identity model.

## 1. Motivation

PHP class members can be `public`, `protected`, or `private`, but top-level
classes, interfaces, traits, enums, functions, and constants have no equivalent
language-level visibility.

A library or application may intend a declaration to be an implementation
detail:

```php
namespace App\Orders;

final class OrderHydrator
{
}
```

Nothing prevents another namespace or package from depending on it:

```php
use App\Orders\OrderHydrator;
```

This creates:

```text
- Accidental coupling to implementation details
- Larger apparent public API surfaces
- Refactoring risk
- Architectural boundaries that exist only in documentation or external tools
- Public contracts that accidentally expose internal types
```

++PHP can enforce the boundary at compile time and still emit ordinary PHP.

## 2. Selected Direction

The following direction is settled:

```text
- Use a top-level `internal` modifier.
- Preserve PHP `namespace` syntax.
- Preserve PHP `use` syntax.
- Do not introduce `module` declarations.
- Do not introduce a new `import` keyword.
- Do not introduce explicit `export` declarations.
- Declarations remain public by default unless marked internal.
- Enforcement is compiler-owned and compile-time.
- Generated PHP retains ordinary symbol names.
- No name mangling is introduced.
- No runtime module registry or ++PHP runtime dependency is introduced.
```

The access boundary of `internal` remains the principal unresolved decision.

## 3. Proposed Syntax

### Class

```php
internal final class OrderHydrator
{
}
```

### Interface

```php
internal interface HydratesOrders
{
}
```

### Trait

```php
internal trait NormalizesOrderData
{
}
```

### Enum

```php
internal enum PersistenceMode
{
    case Insert;
    case Update;
}
```

### Record

```php
internal record InternalOrderState(
    public int $id,
    public string $status,
) {
}
```

### Function

```php
internal function normalizeOrderId(string $value): string
{
    return trim($value);
}
```

### Constant

```php
internal const INTERNAL_ORDER_VERSION = 2;
```

The final RFC must settle the exact parser placement relative to:

```text
final
abstract
readonly
class-like attributes
function by-reference markers
```

The recommended class-like order is consistent with PHP modifier placement:

```php
internal final readonly class Example
{
}
```

subject to native PHP validity after erasure.

## 4. Contextual Keyword

`internal` should be contextual in top-level declaration positions.

Existing identifiers remain legal where unambiguous:

```php
function internal(string $value): string
{
    return $value;
}

$internal = true;
```

The compiler must not rewrite strings, comments, member names, or unrelated
identifier positions.

## 5. Candidate Boundary Models

The RFC cannot be accepted until one boundary model is selected.

### 5.1 Exact Namespace

An internal declaration is accessible only from the same exact namespace.

```text
App\Orders
    can access App\Orders internal declarations.

App\Orders\Http
    cannot access App\Orders internal declarations.
```

Advantages:

```text
- Simple and deterministic.
- Requires no Composer metadata.
- Gives fine-grained boundaries.
```

Disadvantages:

```text
- Child namespaces often represent implementation subdivisions.
- May force unrelated files into one namespace merely to share internals.
- Does not align naturally with package-level library APIs.
```

### 5.2 Namespace Tree

An internal declaration is accessible from its namespace and descendants.

```text
App\Orders
App\Orders\Http
App\Orders\Persistence
```

could share internals rooted at `App\Orders`.

Advantages:

```text
- Useful for bounded contexts and modular monoliths.
- Child namespaces can organize implementation details.
```

Disadvantages:

```text
- The root boundary is not explicit from `internal` alone.
- Parent/child access direction needs precise rules.
- Namespace naming conventions become architectural policy.
```

### 5.3 Composer Package

An internal declaration is accessible anywhere inside the same Composer package
and inaccessible to consuming packages.

Advantages:

```text
- Familiar package-internal meaning.
- Strong fit for library maintainers.
- Derivable from existing Composer/dependency metadata.
- Portable dependency indexes already retain package ownership.
```

Disadvantages:

```text
- Provides no boundaries inside one application package.
- A monolith often has many logical modules in one root package.
- Root-package versus path-repository behavior needs care.
```

### 5.4 Entire Project

An internal declaration is accessible anywhere in the current project but not
from external dependencies/consumers.

Advantages:

```text
- Easy migration for applications.
- Similar to an assembly-level internal concept.
```

Disadvantages:

```text
- Weak architectural protection inside the project.
- Project boundaries are less portable than package boundaries.
- Published multi-package repositories need explicit behavior.
```

### 5.5 Explicit Internal Boundary

A separate configuration or declaration defines a named internal boundary.

Advantages:

```text
- Most expressive.
- Can model bounded contexts independently from namespaces/packages.
```

Disadvantages:

```text
- Reintroduces module-system complexity.
- Adds configuration and tooling ceremony.
- Weakens the simplicity of the selected Option B direction.
```

The prior design discussion recommended starting with Composer-package scope,
but that recommendation has not yet been accepted as the final language rule.

## 6. Access Checks

Once a boundary is selected, the compiler must reject external access through
all statically known forms:

```text
- Imported class-like names
- Fully qualified names
- Function calls
- Constant fetches
- Type declarations
- Generic arguments and bounds
- Attributes
- instanceof
- new expressions
- Static member access
- Class-string constants
- Trait use
- Inheritance and interface implementation
- Checked-error declarations
```

A fully qualified name must not bypass the boundary:

```php
\App\Orders\OrderHydrator $hydrator = new \App\Orders\OrderHydrator();
```

Dynamic class/function names remain governed by existing dynamic-boundary rules.
The compiler cannot promise runtime prevention for a name computed dynamically.

## 7. Public API Leakage

A declaration accessible outside the internal boundary must not expose an
internal declaration through its externally visible contract.

Example:

```php
namespace App\Orders;

internal final class InternalOrderId
{
}

final class Order
{
    public function id(): InternalOrderId
    {
        return new InternalOrderId();
    }
}
```

The public method leaks an internal return type and should be rejected.

The proposed leakage check includes:

```text
- Public and protected constructor parameters
- Public and protected method parameters
- Public and protected return types
- Public and protected property types
- Public record components
- Parent classes
- Implemented interfaces
- Public generic arguments and bounds
- Public type aliases when introduced
- Public checked-error types
- Public class constants whose declared type exposes a class-like declaration
- Trait requirements visible through a public declaration
```

Private implementation details may use internal declarations.

The final RFC must settle whether a public declaration inside the same package
may expose an internal type when every current consumer is package-local. The
recommended rule is based on the declaration's potential external visibility,
not only current call sites.

## 8. Internal Declarations Referencing Public Declarations

Internal code may freely consume public declarations available inside its normal
name-resolution context:

```php
internal final class OrderHydrator
{
    public function hydrate(PublicOrderData $data): Order
    {
        // ...
    }
}
```

Member visibility remains ordinary PHP visibility.

The `internal` modifier applies to the top-level declaration itself, not to all
of its members.

## 9. Lowering

`internal` is erased from native PHP syntax.

Source:

```php
internal final class OrderHydrator
{
}
```

Conceptual generated PHP:

```php
/** @internal */
final class OrderHydrator
{
}
```

The compiler may emit tool-friendly `@internal` metadata, but that metadata is
advisory for native PHP consumers. The authoritative rule is the ++PHP semantic
model.

Generated PHP retains:

```text
- Original namespace
- Original symbol name
- Ordinary PHP visibility/member behavior
- Reflection identity
- Serialization identity
- Stack-trace identity
```

No name mangling or generated proxy is introduced.

## 10. Runtime Limitation

Native PHP can still reference an internal generated declaration:

```php
new \App\Orders\OrderHydrator();
```

The feature promises:

```text
++PHP source cannot statically depend on an internal declaration from outside
its accepted boundary.
```

It does not promise:

```text
The PHP runtime makes the declaration inaccessible to every native PHP caller,
reflection API, dynamic string, or unserializer.
```

This compile-time limitation must be documented prominently.

## 11. Ordinary PHP Interoperability

Open questions include:

```text
- Whether ordinary project-owned PHP may consume internal ++PHP declarations.
- Whether such access is diagnosed only by the full analyzer or by compiler
  declaration/body analysis.
- Whether generated @internal metadata is sufficient for native PHP tooling.
- How a package without ++PHP metadata treats declarations marked @internal in
  PHPDoc.
```

The recommended direction is:

```text
- ++PHP internal metadata is authoritative when available.
- Native PHP consumers receive advisory PHPDoc/tooling diagnostics.
- The compiler should enforce project-owned PHP access where its existing
  contract-oriented PHP analysis can prove the reference.
```

This remains to be finalized.

## 12. Portable Dependency Metadata

Portable dependency indexes should carry:

```text
- Whether a declaration is internal
- Its accepted access boundary identity
- Owning package/project/namespace metadata required by the boundary model
- Declaration source location
- Public API leakage status or compatibility format
```

A downstream ++PHP project must enforce internal visibility without needing the
original `.ppphp` source.

A package without internal metadata remains an open PHP package unless ordinary
PHPDoc/stub rules deliberately provide advisory information.

## 13. Inheritance, Traits, And Interfaces

Potential rules:

```text
- External code cannot extend an internal class.
- External code cannot implement an internal interface.
- External code cannot use an internal trait.
- A public class cannot extend an internal parent across an external boundary.
- A public interface cannot extend an internal interface when that leaks the
  internal contract.
- An internal declaration may extend or implement public declarations.
```

The final RFC must define same-boundary inheritance and downstream package
behavior precisely.

## 14. Functions And Constants

Internal functions and constants follow the same selected boundary as
class-like declarations.

```php
internal function normalizeOrderId(string $value): string
{
    return trim($value);
}

internal const ORDER_STORAGE_VERSION = 2;
```

Generated runtime loading continues to use ordinary Composer/PHP mechanisms.
This RFC does not introduce a separate module bootstrap.

## 15. Attributes And Reflection

The final RFC must decide whether the compiler emits an ordinary marker
attribute in addition to PHPDoc.

A mandatory marker attribute would require a runtime class and is therefore not
recommended.

The preferred initial output is advisory PHPDoc plus portable compiler metadata.

Reflection sees the original PHP declaration and has no native visibility flag
for `internal`.

## 16. Diagnostics

Likely distinct diagnostics include:

```text
- Internal Declaration Is Not Accessible
- Public API Exposes Internal Declaration
- Internal Modifier Is Not Allowed Here
- Internal Boundary Metadata Is Unavailable
- Internal Declaration Conflicts With Public Metadata
```

The compiler should provide related locations for:

```text
- The internal declaration
- The external reference
- The public declaration leaking the type
```

Do not report a generic missing-type diagnostic when the declaration exists but
is inaccessible.

## 17. Tooling

Editor tooling should:

```text
- Resolve definitions of accessible internal declarations.
- Distinguish internal declarations semantically.
- Avoid suggesting inaccessible declarations in auto-import results.
- Preserve rename behavior within the allowed boundary.
- Explain inaccessible symbols rather than presenting them as missing.
```

The first compiler implementation must at minimum preserve definition and
semantic-token correctness.

## 18. Rejected Alternatives

### 18.1 New Module And Import Syntax

Rejected for this direction. PHP already provides namespaces and `use` imports.
The real missing feature is top-level visibility.

### 18.2 Internal-By-Default Closed Modules

Rejected for the initial proposal. The selected model is opt-in `internal` and
public-by-default compatibility with PHP.

### 18.3 Name Mangling

Rejected. It would damage clean output, reflection, serialization, stack traces,
and native interoperability.

### 18.4 Runtime Registry

Rejected. ++PHP should not require a module/visibility runtime.

### 18.5 PHPDoc-Only Source Syntax

Insufficient as the language feature. `@internal` may be emitted for tools, but
++PHP source should have a first-class compiler-owned modifier.

## 19. Decisions Required Before Acceptance

RFC 0007 cannot be marked Accepted until these are settled:

```text
1. The access boundary: exact namespace, namespace tree, Composer package,
   project, or another explicit model.
2. Parent/child namespace behavior.
3. Root project versus installed package behavior.
4. Monorepo/path-repository behavior.
5. Ordinary project-owned PHP access enforcement.
6. Public API leakage rules.
7. Protected member treatment in leakage checks.
8. Generic, checked-error, and attribute leakage.
9. Internal functions and constants.
10. Portable dependency metadata format.
11. Stub and PHPDoc overlay behavior.
12. Diagnostic codes and help.
13. Editor behavior.
14. Exact modifier grammar.
```

## 20. Proposed Acceptance Criteria

Once finalized, Stage 16 should prove:

```text
- Internal declarations parse in every accepted top-level declaration form.
- The selected boundary is deterministic.
- Same-boundary access succeeds.
- External static access fails with an accessibility diagnostic.
- Fully qualified references cannot bypass the check.
- Public APIs cannot leak internal declarations.
- Private implementation details may use internal declarations.
- Inheritance, interfaces, and trait use respect the boundary.
- Internal functions and constants follow the accepted contract.
- Generated PHP contains no ++PHP-only internal syntax.
- Generated names and PHP runtime identity remain unchanged.
- Advisory @internal metadata is deterministic.
- Portable dependency indexes preserve the boundary.
- Downstream ++PHP consumers enforce it source-free.
- Native PHP limitations are documented honestly.
- No new module/import syntax or runtime registry is introduced.
- Source maps, diagnostics, and editor definitions remain accurate.
```
