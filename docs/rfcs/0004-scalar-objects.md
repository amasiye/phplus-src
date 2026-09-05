# RFC 0004 — Scalar Objects

```text
Status: Accepted
Implementation: Scheduled For Stage 15C
```

This RFC settles compiler-owned synthetic properties and methods for PHP's four
scalar types:

```text
int
float
bool
string
```

The source experience is object-like, but generated code continues to use
ordinary unboxed PHP scalars. Scalar Objects improve discoverability,
autocomplete, type checking, and expression composition without introducing a
new runtime object model.

## 1. Motivation

PHP exposes most scalar operations through global functions, casts, or terse
operators:

```php
$name = strtolower($name);
$length = strlen($name);
$absolute = abs($value);
$rounded = round($price, 2);
$inverted = !$enabled;
```

++PHP exposes an intentionally bounded member surface:

```php
string $name = 'Andrew';
int $score = -12;
bool $enabled = true;

echo $name->toLower();
echo $name->length;
echo $score->abs();

if ($enabled->inverted) {
    // ...
}
```

The generated program still operates on native PHP strings, integers, floats,
and booleans.

## 2. Core Architecture

The following rules are normative:

```text
- Scalar Objects cover int, float, bool, and string.
- Scalar values remain native PHP scalars at runtime.
- The compiler performs static member lookup and type checking.
- Supported members lower to ordinary PHP expressions, casts, and functions.
- No boxing allocation is introduced.
- No scalar base class is introduced.
- No runtime registry is introduced.
- No ++PHP runtime package is introduced.
- A complex receiver evaluates exactly once.
- Arguments evaluate eagerly and left to right according to PHP behavior.
- PHP runtime behavior remains authoritative after the accepted lowering.
- The first release is intentionally bounded but useful for all four scalar
  types.
```

The four scalar types do not need equally sized APIs. Members are included for
practical value rather than artificial symmetry.

## 3. Member Classification

The initial convention is:

```text
Property:
    A zero-argument observation or directly derived state of the receiver.

Method:
    A transformation, explicit conversion, or operation requiring arguments.
```

Examples:

```text
Properties:
    length
    isEmpty
    isZero
    isFinite
    inverted

Methods:
    toLower()
    abs()
    clamp()
    round()
    toString()
```

Logical inversion is deliberately available as the `inverted` property because
its meaning is visually explicit during code review:

```php
if ($enabled->inverted) {
}
```

The existing PHP operator remains valid:

```php
if (!$enabled) {
}
```

## 4. String Surface

### 4.1 Properties

```text
length: int
isEmpty: bool
```

Semantics:

```text
length
    Byte length, equivalent to strlen($receiver).

isEmpty
    True exactly when $receiver === ''.
```

### 4.2 Methods

```text
toLower(): string
toUpper(): string
trim(): string
contains(string $needle): bool
startsWith(string $prefix): bool
endsWith(string $suffix): bool
replace(string $search, string $replacement): string
explode(string $separator, int $limit = PHP_INT_MAX): string[]
split(int $chunkLength = 1): string[]
substring(int $offset, ?int $length = null): string
```

Examples:

```php
string $name = 'Andrew';

string $lower = $name->toLower();
int $length = $name->length;
bool $contains = $name->contains('dre');
string[] $parts = $name->explode('d');
string[] $chunks = $name->split(2);
```

### 4.3 String Lowering And Edge Behavior

```text
toLower()
    strtolower($receiver)

toUpper()
    strtoupper($receiver)

trim()
    trim($receiver)

contains($needle)
    str_contains($receiver, $needle)

startsWith($prefix)
    str_starts_with($receiver, $prefix)

endsWith($suffix)
    str_ends_with($receiver, $suffix)

replace($search, $replacement)
    str_replace($search, $replacement, $receiver)

explode($separator, $limit)
    explode($separator, $receiver, $limit)

split($chunkLength)
    str_split($receiver, $chunkLength)

substring($offset, $length)
    substr($receiver, $offset) when $length is omitted or null
    substr($receiver, $offset, $length) otherwise
```

The first string release is byte-oriented:

```text
- Casing follows PHP's ordinary ASCII-oriented behavior.
- `length` counts bytes.
- `split()` divides the string into byte chunks.
- `substring()` follows `substr()` behavior.
- `replace()` is literal, case-sensitive, and replaces every occurrence.
- `trim()` initially exposes no custom character-mask argument.
- `explode()` preserves PHP's positive, zero, and negative limit behavior.
- An empty `explode()` separator preserves PHP's ValueError behavior.
- A `split()` chunk length below one preserves PHP's ValueError behavior.
```

Unicode-aware members require a separate explicit design and must not silently
depend on `mbstring`.

## 5. Integer Surface

### 5.1 Properties

```text
isZero: bool
isPositive: bool
isNegative: bool
isEven: bool
isOdd: bool
```

Semantics:

```text
isZero
    $receiver === 0

isPositive
    $receiver > 0

isNegative
    $receiver < 0

isEven
    $receiver % 2 === 0

isOdd
    $receiver % 2 !== 0
```

Zero is neither positive nor negative.

### 5.2 Methods

```text
abs(): int
absMin(): int
absRaw(): int|float
clamp(int $minimum, int $maximum): int
wrap(int $minimum, int $maximum): int
toFloat(): float
toString(): string
```

Examples:

```php
int $value = -12;

bool $negative = $value->isNegative;
int $absolute = $value->abs();
int $bounded = $value->clamp(0, 100);
int $wrapped = $value->wrap(0, 10);
string $text = $value->toString();
```

### 5.3 Integer Absolute Value

`abs()` provides an integer-only contract:

```text
- For every receiver except PHP_INT_MIN, return abs($receiver) as int.
- PHP_INT_MIN cannot be represented as a positive int on the same platform.
- A statically provable PHP_INT_MIN receiver is a compile-time error.
- A dynamic PHP_INT_MIN receiver throws ValueError.
```

The runtime error must explain the problem and recommend both alternatives:

```text
The absolute value of PHP_INT_MIN cannot be represented as int. Use absMin()
to return PHP_INT_MAX or absRaw() to preserve PHP's native int|float behavior.
```

`absMin()` is the saturating integer-safe alternative:

```text
- PHP_INT_MIN returns PHP_INT_MAX.
- Every other receiver returns the same int as abs().
- The result is always int.
```

`absRaw()` is the explicit one-to-one PHP alias:

```text
- It lowers directly to abs($receiver).
- It preserves PHP's int|float return type and edge behavior.
```

The ordinary `ValueError` thrown by `abs()` for a dynamic unrepresentable value
belongs to PHP's unchecked `Error` hierarchy and creates no checked `throws`
obligation.

### 5.4 Integer Clamp

`clamp()` uses an inclusive range:

```text
- Values below minimum return minimum.
- Values above maximum return maximum.
- Values inside the range return unchanged.
- Equal bounds are valid and return that sole value.
- minimum > maximum is invalid.
```

A statically provable reversed range is a compile-time diagnostic. A dynamic
reversed range throws `ValueError` with this message:

```text
The minimum bound must be less than or equal to the maximum bound.
```

### 5.5 Integer Wrap

`wrap()` uses normalized mathematical modulo over an inclusive integer range.
It wraps rather than clamps:

```php
11->wrap(0, 10);     // 0
(-1)->wrap(0, 10);   // 10
10->wrap(0, 10);     // 10
3->wrap(-2, 2);      // -2
(-3)->wrap(-2, 2);   // 2
```

Rules:

```text
- The interval includes both minimum and maximum.
- Equal bounds return that sole value.
- Reversed bounds follow the same compile-time/runtime error contract as
  clamp().
- Negative receivers use normalized mathematical modulo, not PHP's raw signed
  remainder as the final observable result.
- Lowering must remain exact for the full PHP integer domain and must not rely
  on a float intermediate.
- A full-domain range [PHP_INT_MIN, PHP_INT_MAX] leaves every receiver
  unchanged.
```

### 5.6 Integer Conversions

```text
toFloat()
    Exact explicit `(float) $receiver` cast.

toString()
    Exact explicit `(string) $receiver` cast.
```

Formatting-oriented conversion—locale, thousands separators, decimal places,
currency, padding, and presentation—is outside Scalar Objects. It belongs to a
later formatting-focused proposal.

## 6. Float Surface

### 6.1 Properties

```text
isZero: bool
isPositive: bool
isNegative: bool
isFinite: bool
isInfinite: bool
isNaN: bool
```

### 6.2 Methods

```text
abs(): float
floor(): float
ceil(): float
round(
    int $precision = 0,
    int|RoundingMode $mode = RoundingMode::HalfAwayFromZero,
): float
clamp(float $minimum, float $maximum): float
toInt(): int
toString(): string
```

Float wrapping is not included in the initial release. A continuous wrap needs
a separate decision about half-open intervals, precision, NaN, and infinity.

### 6.3 Float Signs And Special Values

For finite ordinary values:

```text
isZero
    $receiver == 0.0

isPositive
    $receiver > 0.0

isNegative
    $receiver < 0.0
```

For NaN:

```text
isNaN       true
isFinite    false
isInfinite  false
isZero      false
isPositive  false
isNegative  false
```

For positive infinity:

```text
isInfinite  true
isFinite    false
isPositive  true
isNegative  false
isZero      false
```

For negative infinity:

```text
isInfinite  true
isFinite    false
isPositive  false
isNegative  true
isZero      false
```

Both `0.0` and `-0.0` are zero. Neither is positive nor negative. The initial
surface does not add `isNegativeZero`. Native operations and casts may preserve
the negative-zero representation; Scalar Objects do not normalize it globally.

### 6.4 Float Transformations

```text
abs()
    abs($receiver)

floor()
    floor($receiver)

ceil()
    ceil($receiver)

round($precision, $mode)
    round($receiver, $precision, $mode)
```

The initial implementation targets the project's supported PHP 8.4 runtime
contract and accepts both PHP's integer rounding modes and the `RoundingMode`
enum. The default is `RoundingMode::HalfAwayFromZero`.

++PHP is not permanently tied to PHP 8.4. When the supported PHP target advances,
the compiler may use newer equivalent runtime facilities while preserving the
accepted Scalar Object source semantics unless this RFC is amended explicitly.

NaN is preserved by `abs()`, `floor()`, `ceil()`, and `round()` according to the
corresponding PHP operation.

### 6.5 Float Clamp

`clamp()` uses an inclusive ordered interval:

```text
- minimum > maximum is invalid.
- A NaN minimum or maximum is invalid because the interval is unordered.
- A NaN receiver returns NaN.
- Positive infinity clamps to a finite maximum.
- Negative infinity clamps to a finite minimum.
- Infinite bounds are valid when their order is valid.
- Equal bounds are valid.
```

A statically provable invalid interval is a compile-time diagnostic. Dynamic
invalid bounds throw `ValueError`. Reversed bounds use the integer clamp
message. NaN bounds use an explanatory message stating that clamp bounds must
not be NaN.

### 6.6 Float Conversions

```text
toInt()
    Exact explicit `(int) $receiver` cast.

toString()
    Exact explicit `(string) $receiver` cast.
```

`toInt()` follows PHP's truncation, NaN, infinity, range, and platform behavior.
It is deliberately a plain cast rather than a checked exact conversion. A later
proposal may add a strict or fallible conversion such as `toIntExact()` or
`tryToInt()`.

Formatting-oriented float conversion remains outside Scalar Objects.

## 7. Boolean Surface

The boolean surface is intentionally small.

### 7.1 Property

```text
inverted: bool
```

It lowers to:

```php
!$receiver
```

There is no duplicate `inverted()` method.

### 7.2 Conversion Methods

```text
toInt(): int
toString(bool $isHumanReadable = false): string
```

Semantics:

```text
toInt()
    Exact `(int) $receiver` cast, producing 1 for true and 0 for false.

toString(false)
    Exact `(string) $receiver` cast, producing "1" for true and "" for false.

toString(true)
    Produces the lowercase human-readable strings "true" and "false".
```

No additional boolean members are included solely for symmetry.

## 8. Explicit Conversion Only

Scalar conversion members do not introduce implicit coercion.

```php
int $count = 4;
string $label = $count->toString(); // Valid
```

This remains invalid:

```php
string $label = 4;
```

Every approved conversion is explicit. The compiler must preserve the
distinction between:

```text
- A deliberate PHP cast alias.
- A compiler-defined conversion branch such as human-readable bool strings.
- A lossy conversion.
- A platform-sensitive conversion.
```

The initial release does not add string-to-number parsing members.

## 9. Static Typing

Scalar members use the same member and type-flow infrastructure as ordinary
classes, platform declarations, and later List And Map Objects.

Invalid receiver/member combinations receive ordinary compiler diagnostics:

```php
int $number = 4;

$number->toLower();
// Method Does Not Exist for int.
```

A member access or call on a union is valid only when every reachable non-null
arm supports that access with a compatible argument contract. The result is the
canonical union of arm results.

Nullable receivers require nullsafe access:

```php
?string $name = loadName();
?int $length = $name?->length;
```

Direct access on a nullable receiver remains invalid.

## 10. Literal Receivers

Direct scalar-literal member access is supported:

```php
'Andrew'->toLower();
42->toString();
3.14->round(1);
true->inverted;
```

Unary signed expressions require parentheses:

```php
(-42)->abs();
(-3.14)->floor();
```

The sign is a unary operator rather than part of the underlying scalar literal.

## 11. Evaluation Order

The receiver evaluates exactly once:

```php
loadName()->toLower();
```

must call `loadName()` once.

For methods:

```text
1. Evaluate the receiver once.
2. Evaluate arguments eagerly from left to right.
3. Perform inserted validation exactly once.
4. Produce the accepted PHP operation.
```

Synthetic members requiring prerequisite statements use the compiler's
collision-free prerequisite-expression machinery.

## 12. Lowering Contract

Representative lowering rules are:

```text
string::length
    strlen($receiver)

string::toLower()
    strtolower($receiver)

string::contains($needle)
    str_contains($receiver, $needle)

string::explode($separator, $limit)
    explode($separator, $receiver, $limit)

string::split($chunkLength)
    str_split($receiver, $chunkLength)

int::isEven
    $receiver % 2 === 0

int::abs()
    Guard PHP_INT_MIN, then abs($receiver)

int::absMin()
    $receiver === PHP_INT_MIN ? PHP_INT_MAX : abs($receiver)

int::absRaw()
    abs($receiver)

int::clamp($minimum, $maximum)
    Validate bounds, then clamp inclusively

int::wrap($minimum, $maximum)
    Validate bounds, then apply exact normalized inclusive wrapping

float::isFinite
    is_finite($receiver)

float::round($precision, $mode)
    round($receiver, $precision, $mode)

bool::inverted
    !$receiver
```

The compiler may choose any ordinary-PHP lowering that preserves the accepted
semantics, exact evaluation order, source maps, target compatibility, and
absence of a ++PHP runtime dependency.

Generated PHP contains no Scalar Object member syntax.

## 13. Errors And Checked Errors

Runtime validation failures introduced by approved scalar members use built-in
PHP `ValueError` with precise messages.

Because `ValueError` belongs to PHP's unchecked `Error` hierarchy, these members
do not add checked `throws` obligations.

When an invalid condition is statically provable, the compiler reports it before
lowering. Examples include:

```text
- PHP_INT_MIN passed through int::abs().
- clamp() with a literal minimum greater than maximum.
- wrap() with a literal minimum greater than maximum.
- float clamp() with a statically known NaN bound.
- split() with a statically known chunk length below one.
- explode() with a statically known empty separator.
```

## 14. Reflection And Runtime Identity

Synthetic scalar members are compile-time language features.

They do not appear through ordinary PHP reflection, `get_class_methods()`, or
runtime property inspection because no scalar object exists.

Dynamic member names are not supported through this feature:

```php
string $method = 'toLower';
$name->{$method}();
```

Such forms remain governed by existing dynamic-boundary rules.

The runtime type remains exactly the PHP scalar type.

## 15. Interaction With Other Features

Stage 15D List And Map Objects must reuse the same general synthetic-member
architecture while maintaining a separate public API and collection-specific
type rules.

RFC 0004 does not hardcode collection members into the scalar registry.

Records may use scalar members in methods and computed properties after both
features are implemented.

Postfix List Types provide the result spelling used by `explode()` and
`split()` but do not alter their runtime values.

## 16. Rejected Directions

### 16.1 Runtime Boxing

Rejected. Wrapping every scalar would change allocation, extension interop,
serialization, identity assumptions, and performance.

### 16.2 User-Extensible Scalar Prototypes

Rejected for the initial feature. Project-defined global scalar members would
introduce nonlocal semantics, collision rules, portability problems, and hidden
build dependencies.

### 16.3 Equal APIs For All Scalars

Rejected. Member selection follows real usefulness rather than symmetry.

### 16.4 Silent Unicode Semantics

Rejected. The initial string contract does not change according to whether an
optional extension happens to be installed.

### 16.5 Format-Oriented Core Conversions

Rejected from the initial scalar core. Representation conversion and
presentation formatting have different responsibilities.

### 16.6 Float Wrapping

Deferred. A float interval requires separate half-open/closed, precision, NaN,
and infinity semantics.

### 16.7 Implicit Conversion

Rejected. Member conversions do not weaken ++PHP's strict assignment and call
rules.

## 17. Accepted Decision Summary

| Decision | Accepted Contract |
| --- | --- |
| D1 | `explode()` preserves PHP delimiter-splitting behavior and the optional limit. |
| D2 | `split()` aliases `str_split()` and accepts a chunk length. |
| D3 | `substring()`, `replace()`, casing, and initial `trim()` behavior follow the specified PHP operations. |
| D4 | Integer sign, zero, and parity properties are accepted. |
| D5 | Integer `abs()` remains int-only and errors on PHP_INT_MIN; `absMin()` saturates to PHP_INT_MAX; `absRaw()` preserves PHP's `int|float` behavior. |
| D6 | `clamp()` uses inclusive bounds and rejects minimum greater than maximum. |
| D7 | Integer `wrap()` uses normalized mathematical modulo over an inclusive range. |
| D8 | Presentation formatting is deferred. |
| D9 | Float wrapping is deferred. |
| D10 | NaN behavior is explicit and NaN bounds are rejected. |
| D11 | Infinity behavior is explicit and ordered infinite bounds are supported. |
| D12 | Positive and negative zero both satisfy `isZero` and neither sign property. |
| D13 | Float `toInt()` is a plain PHP cast. |
| D14 | Boolean inversion is the `inverted` property. |
| D15 | Boolean `toString()` accepts the human-readable switch. |
| D16 | Boolean includes `inverted`, `toInt()`, and `toString()`. |
| D17 | Literal receivers are accepted; unary signed receivers require parentheses. |
| D18 | Observations are properties; transformations and conversions are methods. |
| D19 | Inserted bound failures use unchecked `ValueError`. |
| D20 | Initial lowering targets supported PHP 8.4 behavior without permanently locking ++PHP to PHP 8.4. |
| D21 | Ordinary compiler diagnostics and exact source mappings apply. |

## 18. Stage 15C Acceptance Criteria

Stage 15C is complete only when:

```text
- All four scalar types implement the exact accepted initial contract.
- Every synthetic member is compiler-owned and statically type-checked.
- Invalid receivers and arguments receive ordinary source diagnostics.
- Compile-time-provable runtime validation failures are diagnosed.
- Dynamic validation failures use the accepted ValueError contracts.
- Nullable and union receivers remain sound.
- Literal receivers work in every accepted parser context.
- Complex receivers evaluate exactly once.
- Method arguments preserve eager left-to-right evaluation.
- Integer abs(), absMin(), and absRaw() preserve their distinct contracts.
- Integer clamp() and wrap() are exact across the supported integer domain.
- Float NaN, infinity, and negative-zero behavior matches this RFC.
- Boolean human-readable and raw conversions match this RFC.
- Generated PHP contains no Scalar Object member syntax.
- Generated values remain native PHP scalars.
- Generated PHP passes php -l and runs on the configured target.
- No boxing, wrapper, runtime registry, or ++PHP runtime dependency exists.
- Editor definition, hover, completion, and semantic information can describe
  synthetic members.
- Source maps associate generated operations and inserted guards with the
  original member access or call.
- Reflection continues to observe native PHP scalar values only.
- Every prior language, analysis, cache, build, diagnostic, browser, and
  interoperability guarantee remains green.
```
