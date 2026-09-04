# RFC 0003 — Postfix List Types

```text
Status: Accepted
Implementation: Scheduled For Stage 15B
```

This RFC settles postfix list-type syntax for ++PHP. It introduces an alternate
source spelling for the existing `array<T>` list type; it does not introduce a
second collection model, runtime wrapper, or new array semantics.

## 1. Motivation

++PHP already supports first-class typed lists:

```php
array<int> $scores = [56, 88, 90];
array<string> $names = ['Matthew', 'Mark'];
```

That spelling remains valid and is particularly useful beside the existing map
form `array<K, V>`. For simple and nested list types, however, a postfix spelling
is often more compact and easier to scan:

```php
int[] $scores = [56, 88, 90];
string[] $names = ['Matthew', 'Mark'];
ShoppingCartItem<Product>[] $items = [];
int[][] $matrix = [[1, 2], [3, 4]];
```

The feature is deliberately syntactic. All existing list typing, invariance,
readonly, lowering, PHPDoc, interoperability, and runtime rules remain
unchanged.

## 2. Core Equivalence

The normative rule is:

```text
T[] is exactly equivalent to array<T>.
```

Examples:

```text
int[]                         ≡ array<int>
string[]                      ≡ array<string>
User[]                        ≡ array<User>
Box<Product>[]                ≡ array<Box<Product>>
int[][]                       ≡ array<array<int>>
(string|null)[]               ≡ array<string|null>
```

Both spellings produce the same semantic `TypedArrayType` list representation.
They have the same assignability, invariance, key, value, mutation, and erasure
behavior.

The compiler must not preserve a semantic distinction between the two source
spellings after parsing. It may retain the original spelling only for source
mapping, diagnostics, formatting, or editor presentation.

## 3. Maps Remain Explicit

Postfix syntax always denotes a list. The associative/map form remains:

```php
array<string, int> $scores = [
    'Matthew' => 88,
    'Mark' => 90,
];
```

There is no postfix map syntax in this RFC.

The following is not a map declaration:

```php
Pair<string, int>[] $pairs = [];
```

It is a list whose elements are `Pair<string, int>`.

## 4. Grammar And Binding

Conceptually:

```text
postfix-list-type
    ::= primary-type "[]"
      | parenthesized-type "[]"
      | postfix-list-type "[]"
```

The postfix operator binds to the immediately preceding type before an
unparenthesized union or intersection is completed.

Therefore:

```php
int|string[] $value;
```

means:

```php
int|array<string> $value;
```

It does not mean:

```php
array<int|string> $value;
```

A list of union elements requires parentheses:

```php
(int|string)[] $values;
```

which is equivalent to:

```php
array<int|string> $values;
```

Likewise:

```text
A&B[]
    means A&array<B>

(A&B)[]
    means array<A&B>
```

Existing PHP and ++PHP composite-type validity rules still apply. Postfix list
syntax does not make an otherwise invalid union, intersection, or DNF type
valid.

## 5. Nullability

A nullable prefix applies to the complete postfix list type:

```php
?string[] $names;
```

means:

```php
?array<string> $names;
```

or equivalently:

```text
array<string>|null
```

A list whose elements may be null is written:

```php
(string|null)[] $names;
```

which means:

```text
array<string|null>
```

These are distinct contracts:

```text
?string[]
    The list itself may be null; every present element is string.

(string|null)[]
    The list is present; an element may be string or null.
```

A nullable list of nullable elements may be written:

```php
?(string|null)[] $names;
```

provided the final frontend grammar can represent it without ambiguity. The
canonical semantic type is:

```text
array<string|null>|null
```

## 6. Readonly

`readonly` remains a declaration or storage modifier. It is not part of the
postfix element type.

```php
readonly string[] $names = ['Matthew', 'Mark'];
```

means a readonly local binding containing `array<string>`.

It does not mean that `string` has a readonly-qualified form.

Existing ++PHP rules continue to reject readonly modifiers inside type
arguments or atomic element types.

## 7. Supported Type Positions

`T[]` is supported in every type position where `array<T>` is supported,
including:

```text
- Local declarations
- Readonly local declarations
- Function parameters
- Method parameters
- Function returns
- Method returns
- Properties
- Constructor-promoted properties
- Property-hook declarations
- Generic arguments
- Generic bounds only where the equivalent array<T> bound is legal
- Union members
- Intersection-compatible positions subject to existing PHP rules
- Nullable types
- Nested list and map types
- Closure parameters and returns
- Arrow-function parameters and returns
- Typed loop declarations
- Record components after Stage 15A
```

Examples:

```php
function names(): string[]
{
    return ['Matthew', 'Mark'];
}

function save(string[] $names): void
{
}

final class Team
{
    public User[] $members = [];
}

Box<Product>[] $boxes = [];

array<string, User[]> $usersByTeam = [];
```

## 8. Type Checking

All existing typed-list rules apply unchanged.

```php
int[] $scores = [56, 88, 90];
$scores[] = 95;      // Valid
$scores[] = 'high';  // Error
```

Typed lists remain invariant in the applicable MVP/post-MVP type system:

```text
Dog[] is not assignable to Animal[] merely because Dog extends Animal.
```

Any future hierarchy-aware collection variance or widening requires a separate
RFC and applies equally to `T[]` and `array<T>`.

List keys remain integers according to the existing typed-array contract and
observable PHP array behavior.

## 9. Lowering And PHPDoc

Postfix list syntax is erased exactly like `array<T>`.

Source:

```php
string[] $names = ['Matthew', 'Mark'];
```

Conceptual generated PHP:

```php
/** @var list<string> $names */
$names = ['Matthew', 'Mark'];
```

Source:

```php
function names(): string[]
{
    return ['Matthew', 'Mark'];
}
```

Conceptual generated PHP:

```php
/** @return list<string> */
function names(): array
{
    return ['Matthew', 'Mark'];
}
```

Nested lists preserve nested PHPDoc:

```text
int[][]
    native PHP: array
    PHPDoc: list<list<int>>
```

No wrapper, helper function, base class, runtime registry, or ++PHP runtime
library is introduced.

## 10. Diagnostics And Source Preservation

Diagnostics should preserve the source spelling where it helps the developer.
For example, an assignment error against `string[]` should not unnecessarily
rewrite the expected type as `array<string>` in the primary message.

Semantic identity, equality, substitution, compatibility, and caching use the
canonical `array<T>` list representation.

Source maps must preserve exact spans for:

```text
- The element type
- Every [] suffix
- Parentheses controlling precedence
- Nullable prefixes
- Nested generic applications
```

## 11. Interoperability

Ordinary PHP and PHPDoc continue to expose lists through existing native `array`
and PHPDoc `list<T>`/`array<int, T>` forms.

A PHPDoc declaration such as:

```php
/** @param list<User> $users */
function saveUsers(array $users): void
{
}
```

is compatible with a ++PHP call using:

```php
User[] $users = [];
saveUsers($users);
```

Generated PHP never contains postfix list syntax.

## 12. Rejected Alternatives

### 12.1 A Second Runtime List Type

Rejected. `T[]` is not a wrapper, collection class, vector, or custom runtime
container. PHP arrays remain authoritative at runtime.

### 12.2 Postfix Map Syntax

Rejected for this RFC. `array<K, V>` remains explicit and readable, while a
postfix map syntax would introduce another grammar and likely collide with array
shape or tuple notation.

### 12.3 `?T[]` Means Nullable Elements

Rejected. Prefix nullability applies to the complete type expression it
modifies. Nullable elements require `(T|null)[]`.

### 12.4 Implicit Covariance

Rejected. A spelling alias must not silently change the existing typed-list
compatibility contract.

## 13. Implementation Requirements

Stage 15B must:

```text
- Extend the token-aware ++PHP frontend rather than use regex transformation.
- Parse nested postfix list suffixes in every approved type position.
- Implement the precedence and nullability rules in this RFC.
- Canonicalize immediately to the existing TypedArrayType list model.
- Preserve original spans and source spelling for diagnostics and tooling.
- Reuse existing typed-array semantic checks.
- Reuse existing generic substitution and PHPDoc emission.
- Erase every postfix list type from generated native PHP.
- Preserve deterministic output and source maps.
- Add editor definition and semantic-token coverage.
- Require no runtime helper or dependency.
```

## 14. Acceptance Criteria

RFC 0003 is implemented only when:

```text
1. T[] and array<T> produce the same semantic type.
2. Maps remain array<K, V>.
3. Nested lists work.
4. Generic element types work.
5. int|string[] binds as int|array<string>.
6. (int|string)[] binds as array<int|string>.
7. ?string[] means array<string>|null.
8. (string|null)[] means array<string|null>.
9. readonly modifies the declaration, not the element type.
10. Every existing array<T> type position accepts T[].
11. Existing list invariance and mutation rules remain unchanged.
12. Generated PHP contains no postfix list syntax.
13. Generated PHPDoc preserves list element types.
14. Nested PHPDoc is correct.
15. Diagnostics preserve useful original spelling.
16. Source maps cover every postfix token accurately.
17. Ordinary PHP/PHPDoc interoperability remains green.
18. No wrapper object or ++PHP runtime dependency is introduced.
19. Existing array<T> syntax remains fully supported.
20. All prior-stage tests remain green.
```
