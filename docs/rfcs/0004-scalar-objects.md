# RFC 0004 — Scalar Objects

```text
Status: Draft
Implementation: Proposed For Stage 15C
```

This RFC proposes compiler-owned synthetic properties and methods for PHP's four
scalar types:

```text
int
float
bool
string
```

The source experience is object-like, but generated code continues to use
ordinary unboxed PHP scalars. This draft records the settled architectural
constraints and presents an initial member surface for design review.

## 1. Motivation

PHP exposes most scalar operations as global functions or operators:

```php
$name = strtolower($name);
$length = strlen($name);
$absolute = abs($value);
$rounded = round($price, 2);
```

++PHP can provide a discoverable, typed member surface while preserving PHP's
runtime model:

```php
string $name = 'Andrew';

echo $name->toLower();
echo $name->length;
```

The feature should improve readability, discoverability, autocomplete, generic
expression typing, and composition without introducing runtime wrappers.

## 2. Settled Architecture

The following decisions are already settled:

```text
- Scalar Objects cover int, float, bool, and string.
- Scalar values remain native PHP scalars at runtime.
- The compiler performs static member lookup and type checking.
- The compiler lowers supported members to ordinary PHP expressions/functions.
- No boxing allocation is introduced.
- No scalar base class is introduced.
- No runtime registry is introduced.
- No ++PHP runtime package is introduced.
- Complex receivers evaluate exactly once.
- PHP runtime behavior remains authoritative after lowering.
- The first release is intentionally bounded but must be useful for all four
  scalar types.
```

The four scalar types do not need equal-sized APIs. In particular, `bool` must
not receive artificial members merely to match the size of the string surface.

## 3. Member Classification

The proposed convention is:

```text
Property:
    A zero-argument observation derived from the current scalar value.

Method:
    An operation that accepts input, exposes an explicit conversion, or applies
    a transformation whose invocation should be visually explicit.
```

This convention remains subject to review for zero-argument transformations
such as absolute value, floor, ceiling, and logical inversion.

## 4. String Surface

The initial string surface is substantially settled.

### Properties

```text
length: int
isEmpty: bool
```

### Methods

```text
toLower(): string
toUpper(): string
trim(): string
contains(string $needle): bool
startsWith(string $prefix): bool
endsWith(string $suffix): bool
replace(string $search, string $replacement): string
split(string $separator): string[]
substring(int $offset, ?int $length = null): string
```

Examples:

```php
string $name = 'Andrew';

string $lower = $name->toLower();
int $length = $name->length;
bool $contains = $name->contains('dre');
string[] $parts = $name->split('d');
```

Initial string semantics are byte-oriented and correspond to ordinary PHP string
operations. Unicode-aware members require a separate explicit design and must
not silently introduce an `mbstring` dependency.

Questions still requiring final wording include:

```text
- Whether split('') is rejected, follows a deliberate character-splitting
  contract, or mirrors one exact PHP operation.
- Whether substring() follows substr() edge behavior exactly.
- Whether replace() is literal-only and therefore lowers to str_replace().
- Whether casing behavior should be documented explicitly as locale-insensitive
  ASCII/PHP behavior for the first release.
```

## 5. Integer Surface

The initial integer API should cover common inspection, arithmetic convenience,
and deliberate conversion without becoming a general mathematics library.

### Candidate Properties

```text
isZero: bool
isPositive: bool
isNegative: bool
isEven: bool
isOdd: bool
```

### Candidate Methods

```text
abs(): int
clamp(int $minimum, int $maximum): int
toFloat(): float
toString(): string
```

Examples under consideration:

```php
int $value = -12;

bool $negative = $value->isNegative;
int $absolute = $value->abs();
int $bounded = $value->clamp(0, 100);
string $text = $value->toString();
```

Open decisions:

```text
- `abs()` versus an `absolute` property.
- Behavior for abs(PHP_INT_MIN), whose mathematical absolute value cannot fit
  in an int on the same platform.
- Whether clamp() rejects minimum > maximum or adopts another deterministic
  contract.
- Whether toString() is always equivalent to `(string) $value`.
- Whether format-oriented conversion belongs outside the scalar core.
```

## 6. Float Surface

The float API must make IEEE/PHP edge cases explicit rather than hide them.

### Candidate Properties

```text
isZero: bool
isPositive: bool
isNegative: bool
isFinite: bool
isInfinite: bool
isNaN: bool
```

### Candidate Methods

```text
abs(): float
floor(): float
ceil(): float
round(int $precision = 0): float
clamp(float $minimum, float $maximum): float
toInt(): int
toString(): string
```

Examples under consideration:

```php
float $price = 18.75;

float $rounded = $price->round(2);
bool $finite = $price->isFinite;
int $whole = $price->toInt();
```

Open decisions:

```text
- Exact rounding-mode support and whether it follows the target PHP version's
  native rounding enum/constants.
- NaN behavior for sign queries, clamp(), and comparisons.
- Infinity behavior for conversions and clamp().
- Whether negative zero is negative, zero, both, or exposed through a separate
  property.
- Exact float-to-int overflow and truncation behavior.
- Whether toString() uses a plain cast or a deterministic compiler-defined
  representation.
```

## 7. Boolean Surface

The bool surface should remain small and honest.

### Candidate Property Or Method

One of the following approaches should be selected:

```text
not: bool property
inverse: bool property
negate(): bool method
```

The draft does not yet select a spelling.

### Candidate Conversion Methods

```text
toInt(): int
toString(): string
```

Open decisions:

```text
- Whether logical inversion deserves a member when `!$value` already exists.
- Whether toString() follows PHP's cast (`true` => "1", `false` => "") or uses
  human-readable `"true"` / `"false"`.
- Whether toInt() is useful enough for the initial surface.
- Whether bool should initially expose no conversion members and participate
  only through a single inversion property/method.
```

The final RFC must not add bool members solely for symmetry.

## 8. Explicit Conversions

Scalar conversion members must not become implicit coercion rules.

For example:

```php
int $count = 4;
string $label = $count->toString();
```

may be valid while this remains invalid under strict ++PHP assignment rules:

```php
string $label = 4;
```

The final RFC must distinguish:

```text
- Existing PHP cast behavior preserved deliberately.
- Compiler-defined conversion behavior.
- Conversions that can lose information.
- Conversions that may produce platform-dependent results.
```

## 9. Static Typing

The compiler must resolve scalar members through the same member/type-flow
infrastructure used for classes, platform declarations, and later list/map
objects.

Invalid receiver/member combinations receive normal compiler diagnostics:

```php
int $number = 4;

$number->toLower();
// Method Does Not Exist for int.
```

A member is available on a union only when every reachable non-null arm supports
one compatible contract.

Nullable receivers require nullsafe access:

```php
?string $name = loadName();
?int $length = $name?->length;
```

The final RFC must settle whether scalar members may be called on literals
without parentheses in every parser context:

```php
'Andrew'->toLower();
42->toString();
```

## 10. Evaluation Order

A receiver is evaluated exactly once.

```php
loadName()->toLower();
```

must call `loadName()` once.

For methods, PHP's left-to-right expression behavior and ordinary eager argument
evaluation must be preserved by lowering.

Synthetic properties or methods that need prerequisite statements must use the
compiler's established collision-free prerequisite-expression machinery.

## 11. Lowering

Conceptual examples:

```text
string::length
    strlen($receiver)

string::toLower()
    strtolower($receiver)

string::contains($needle)
    str_contains($receiver, $needle)

int::isEven
    $receiver % 2 === 0

int::abs()
    abs($receiver)

float::isFinite
    is_finite($receiver)

float::round($precision)
    round($receiver, $precision)
```

These examples are candidates until their edge behavior is accepted.

Lowering must be target-PHP-aware where availability or behavior differs.
Generated PHP must contain no scalar-member syntax.

## 12. Reflection And Runtime Identity

Synthetic scalar members are compile-time language features.

They do not appear through ordinary PHP reflection, `get_class_methods()`, or
runtime property inspection because no scalar object exists.

Dynamic member names are not supported through this feature:

```php
string $method = 'toLower';
$name->{$method}();
```

Such forms remain governed by the existing dynamic-boundary rules.

## 13. Interaction With Later Features

Stage 15D List And Map Objects should reuse the same general synthetic-member
architecture while maintaining a distinct public API and collection-specific
type rules.

RFC 0004 must not hardcode collection members into the scalar registry.

Records may use scalar members in methods and computed properties once both
features are implemented.

## 14. Rejected Directions

### 14.1 Runtime Boxing

Rejected. Wrapping every scalar would change identity, allocation, extension
interop, serialization, and performance.

### 14.2 User-Extensible Scalar Prototypes

Rejected for the initial feature. Allowing projects to add members globally
would create nonlocal semantics, collision rules, and portability problems.

### 14.3 Equal APIs For All Scalars

Rejected. Member selection must follow real usefulness rather than symmetry.

### 14.4 Silent Unicode Semantics

Rejected. The initial string contract must not change based on whether an
optional extension happens to be installed.

## 15. Decisions Required Before Acceptance

RFC 0004 cannot be marked Accepted until the following are settled:

```text
1. Exact int member names and contracts.
2. Exact float member names and contracts.
3. Exact bool member names and contracts.
4. Final property-versus-method rule for zero-argument transformations.
5. Conversion semantics for every approved conversion member.
6. Numeric overflow and platform-bound behavior.
7. NaN, infinity, and negative-zero behavior.
8. String split, substring, replacement, and casing edge behavior.
9. Literal receiver grammar.
10. Exact lowering table for the initial surface.
11. Target-PHP compatibility rules.
12. Diagnostic and source-map behavior.
```

## 16. Proposed Acceptance Criteria

Once finalized, Stage 15C should prove:

```text
- All four scalar types have an intentionally reviewed initial contract.
- Every member is type-checked by the compiler.
- Invalid members receive ordinary source diagnostics.
- Nullable and union receivers are sound.
- Complex receivers evaluate once.
- Generated PHP contains no scalar-member syntax.
- Generated values remain native PHP scalars.
- Runtime behavior matches the accepted lowering table.
- No boxing, wrapper, runtime registry, or ++PHP runtime dependency exists.
- Editor definition/hover/semantic information can describe synthetic members.
- Source maps point diagnostics and generated expressions to original members.
- All prior language, analysis, and build guarantees remain green.
```
