# RFC 0005 — List And Map Objects

```text
Status: Draft
Implementation: Proposed For Stage 15D
Depends On: RFC 0003 — Postfix List Types
Incorporates: RFC 0002 — List And Map Path Access
```

This RFC proposes compiler-owned synthetic properties and methods for ++PHP
lists and maps while retaining ordinary PHP arrays at runtime.

The feature applies to:

```text
array<T>
T[]
array<K, V>
array
```

RFC 0002 is already Accepted and remains authoritative for the `get()` and
`hasPath()` path-access contract. This RFC places those members inside the wider
List And Map Objects surface without reopening their settled semantics.

## 1. Motivation

PHP arrays are powerful but expose most operations through global functions:

```php
$count = count($names);
$keys = array_keys($scores);
$filtered = array_values(array_filter($names, $predicate));
```

The relationship between an operation and its collection is often less
immediately discoverable than an object-style API, and type relationships can
be obscured when multiple generic array functions are composed.

++PHP can provide a statically typed member surface:

```php
string[] $names = ['Matthew', 'Mark'];

int $count = $names->count;
?string $first = $names->first;
string[] $short = $names->filter(
    fn (string $name, int $index): bool => $name->length <= 4,
);
```

Generated code remains ordinary PHP arrays and functions.

## 2. Settled Architecture

The following decisions are settled:

```text
- Lists and maps remain native PHP arrays.
- No collection wrapper is introduced.
- No list or map base class is introduced.
- No runtime registry is introduced.
- No ++PHP runtime package is introduced.
- Synthetic members are resolved and type-checked by the compiler.
- Supported members lower to ordinary PHP expressions and functions.
- Complex receivers evaluate exactly once.
- List and map contracts remain distinct.
- `T[]` and `array<T>` expose the same list members.
- A member without `Key` concerns values.
- A member with `Key` concerns keys.
- Collection callbacks receive value first and key second.
- Reducers receive accumulator, value, then key.
- List filter results are reindexed.
- Map filter results preserve keys.
- Map `map()` transforms values and preserves keys.
- Mutation-oriented members are outside the initial release.
```

## 3. Receiver Categories

### 3.1 Typed List

```text
array<T>
T[]
```

Canonical contract:

```text
key: int
value: T
```

### 3.2 Typed Map

```text
array<K, V>
```

Canonical contract:

```text
key: K
value: V
```

`K` must continue to obey the existing PHP array-key contract.

### 3.3 Broad PHP Array

```text
array
```

The compiler knows only:

```text
key: int|string
value: mixed
shape: list or map unknown
```

Broad arrays should receive a useful but appropriately broad member surface.
The final RFC must not fabricate list shape or a narrower value type.

## 4. Naming Convention

The normative naming rule is:

```text
No `Key` suffix:
    The member concerns, tests, transforms, or returns values.

`Key` suffix:
    The member concerns, tests, transforms, or returns keys.
```

Examples:

```php
string[] $names = ['Matthew', 'Mark'];

?string $firstName = $names->first;
?int $firstIndex = $names->firstKey;
```

```php
array<string, int> $scores = [
    'Matthew' => 88,
    'Mark' => 90,
];

?int $firstScore = $scores->first;
?string $firstName = $scores->firstKey;
```

This convention follows PHP's distinction between value-oriented and
key-oriented array operations.

## 5. Proposed Observational Properties

The initial release should include:

```text
count
isEmpty
first
firstKey
last
lastKey
keys
values
```

### 5.1 List Contracts

```text
T[]::count: int
T[]::isEmpty: bool
T[]::first: T|null
T[]::firstKey: int|null
T[]::last: T|null
T[]::lastKey: int|null
T[]::keys: int[]
T[]::values: T[]
```

### 5.2 Map Contracts

```text
array<K, V>::count: int
array<K, V>::isEmpty: bool
array<K, V>::first: V|null
array<K, V>::firstKey: K|null
array<K, V>::last: V|null
array<K, V>::lastKey: K|null
array<K, V>::keys: K[]
array<K, V>::values: V[]
```

### 5.3 Broad Array Contracts

Candidate broad-array contracts:

```text
array::count: int
array::isEmpty: bool
array::first: mixed
array::firstKey: int|string|null
array::last: mixed
array::lastKey: int|string|null
array::keys: (int|string)[]
array::values: mixed[]
```

Because `mixed` already includes `null`, broad-array `first` and `last` do not
need a distinct nullable wrapper.

Open decisions:

```text
- Whether first/last should be properties or zero-argument methods.
- Exact PHP 8.4 lowering for first/last without evaluating a receiver twice.
- Whether `values` on an already typed list should return the receiver when
  safe or always lower through array_values().
- Whether keys/values should preserve readonly binding information only at the
  receiving declaration, as normal ++PHP values do.
```

## 6. Proposed Query Methods

The initial query surface is:

```text
contains(value)
containsKey(key)
find(predicate)
findKey(predicate)
any(predicate)
all(predicate)
get(location, default = null)
hasPath(location)
```

### 6.1 `contains()`

Candidate contracts:

```text
T[]::contains(T $value): bool
array<K, V>::contains(V $value): bool
array::contains(mixed $value): bool
```

The final RFC must settle strict versus loose comparison. The recommended
starting point is strict identity-compatible comparison, corresponding to
`in_array($value, $receiver, true)`, because ++PHP's type system should not
silently introduce PHP's broad loose-comparison behavior.

### 6.2 `containsKey()`

Candidate contracts:

```text
T[]::containsKey(int $key): bool
array<K, V>::containsKey(K $key): bool
array::containsKey(int|string $key): bool
```

`containsKey()` checks one exact direct key. A dot in a string key has no path
meaning.

It should follow `array_key_exists()` semantics, so a present key containing
`null` still exists.

### 6.3 `find()` And `findKey()`

Callbacks receive value first and key second:

```php
array<string, int> $scores = [
    'Matthew' => 88,
    'Mark' => 90,
];

?int $score = $scores->find(
    fn (int $score, string $name): bool => $score >= 90,
);

?string $name = $scores->findKey(
    fn (int $score, string $name): bool => $score >= 90,
);
```

Candidate contracts:

```text
T[]::find(callable(T, int): bool $predicate): T|null
T[]::findKey(callable(T, int): bool $predicate): int|null

array<K, V>::find(callable(V, K): bool $predicate): V|null
array<K, V>::findKey(callable(V, K): bool $predicate): K|null
```

A found `null` value and no found value cannot be distinguished through
`find()` alone. This mirrors the already accepted `get()` default/null trade-off
and must be documented honestly.

### 6.4 `any()` And `all()`

Candidate contracts:

```text
T[]::any(callable(T, int): bool $predicate): bool
T[]::all(callable(T, int): bool $predicate): bool

array<K, V>::any(callable(V, K): bool $predicate): bool
array<K, V>::all(callable(V, K): bool $predicate): bool
```

The final RFC must settle empty-collection behavior. The recommended mathematical
contract is:

```text
empty.any(...) == false
empty.all(...) == true
```

### 6.5 `get()` And `hasPath()`

RFC 0002 is authoritative.

Accepted examples:

```php
$config->get('database.port', 3306);
$config->hasPath('database.port');
$config->get(['build.version'], 'unknown');
```

Accepted location forms:

```text
int
string dot-path
(int|string)[] exact segment list
```

Accepted semantics include:

```text
- A string without a dot is one direct string key.
- Integer location is one direct integer key.
- Dot strings traverse nested arrays.
- Explicit segment lists preserve literal dots and dynamic segments.
- Missing segment returns the default or false.
- Non-array intermediate returns the default or false.
- Present null remains present.
- Empty explicit path identifies the receiver.
- Receiver, location, and default evaluate once in that order.
- The default is eagerly evaluated.
- No dot escaping or slash notation is included initially.
```

RFC 0005 must not rename `get()` to `getPath()`.

## 7. Proposed Transformation Methods

The initial transformation surface is:

```text
filter(predicate)
map(mapper)
reduce(reducer, initial)
```

### 7.1 `filter()`

List contract:

```text
T[]::filter(callable(T, int): bool $predicate): T[]
```

List results are reindexed.

Map contract:

```text
array<K, V>::filter(callable(V, K): bool $predicate): array<K, V>
```

Map keys are preserved.

### 7.2 `map()`

List contract:

```text
T[]::map<U>(callable(T, int): U $mapper): U[]
```

Map contract:

```text
array<K, V>::map<U>(callable(V, K): U $mapper): array<K, U>
```

For maps, `map()` transforms values and preserves keys.

A future `mapKeys()` requires a separate collision policy and is not part of the
initial release.

### 7.3 `reduce()`

Callbacks receive accumulator, value, then key:

```text
T[]::reduce<U>(callable(U, T, int): U $reducer, U $initial): U

array<K, V>::reduce<U>(callable(U, V, K): U $reducer, U $initial): U
```

The explicit initial value is required in the first release. Omitting it would
require a separate empty-array and accumulator-inference contract.

## 8. Specialized List Members

The first release should include:

```text
string[]::join(string $separator): string
```

Example:

```php
string[] $names = ['Matthew', 'Mark'];
string $label = $names->join(', ');
```

Open decisions:

```text
- Whether arrays of Stringable objects are accepted.
- Whether non-string scalar lists receive implicit conversion.
- Whether the method should remain string[]-only initially.
```

The recommended initial contract is strictly `string[]`.

## 9. Callback Typing

The compiler must provide contextual callback parameter and return types.

For:

```php
array<string, int> $scores = [];

array<string, string> $labels = $scores->map(
    fn (int $score, string $name): string => "{$name}: {$score}",
);
```

it knows:

```text
value: int
key: string
result: string
```

Explicit callback types must remain compatible with the collection contract.

The RFC must settle whether callbacks may omit native parameter types in this
context. The existing ++PHP strict declaration contract generally requires
explicit types; contextual inference should not be introduced accidentally.
The recommended initial rule is to retain explicit callback parameter and return
types.

## 10. Readonly Receivers

Observational, query, and non-mutating transformation members are valid on a
readonly collection binding because they do not mutate the stored array.

```php
readonly string[] $names = ['Matthew', 'Mark'];

int $count = $names->count;
string[] $short = $names->filter(
    fn (string $name, int $index): bool => $name->length <= 4,
);
```

The transformation returns a new array value.

No in-place mutating members are included in the initial release.

## 11. Evaluation Order

For:

```php
loadScores()->map(loadMapper());
```

++PHP must preserve:

```text
1. Receiver evaluation once.
2. Argument evaluation once.
3. Left-to-right evaluation.
4. Ordinary eager argument evaluation.
```

Synthetic properties such as `first` must also evaluate complex receivers once.

The compiler may use collision-free prerequisite temporaries.

## 12. Lowering

Conceptual lowering candidates include:

```text
count
    count($receiver)

isEmpty
    $receiver === []

firstKey
    array_key_first($receiver)

lastKey
    array_key_last($receiver)

keys
    array_keys($receiver)

values
    array_values($receiver)

contains
    in_array($value, $receiver, true)

containsKey
    array_key_exists($key, $receiver)
```

`first`, `last`, callback queries, transformations, and path traversal may need
prerequisite statements to preserve evaluation and shape.

Lowering must be target-PHP-aware. The compiler cannot assume a function exists
in the target merely because it exists on the compiler host.

The final RFC must provide the complete lowering table for every accepted
member.

## 13. Type Inference

Members must preserve structured collection types.

Examples:

```text
string[]::filter(...)       -> string[]
string[]::map<int>(...)     -> int[]
array<string, int>::filter  -> array<string, int>
array<string, int>::map<U>  -> array<string, U>
array<string, int>::keys    -> string[]
array<string, int>::values  -> int[]
```

RFC 0002 governs path-sensitive inference for literal `get()` locations.

Broad arrays retain broad results unless the compiler can prove a narrower
contract.

## 14. Dynamic Member Access

Synthetic collection members are compile-time features.

Dynamic member names do not resolve through this API:

```php
string $method = 'filter';
$items->{$method}($predicate);
```

Such forms remain governed by existing dynamic-boundary rules.

The members do not appear through PHP reflection because the runtime receiver is
an array, not an object.

## 15. Mutation-Oriented Members Deferred

The initial RFC does not include:

```text
push
pop
shift
unshift
sort
reverseInPlace
insert
remove
setPath
removePath
```

These require separate decisions about:

```text
- In-place mutation versus returned copies
- Readonly receivers
- Lvalue requirements
- PHP references
- Key preservation
- Sorting stability
- Fluent chaining
```

## 16. Rejected Alternatives

### 16.1 Runtime Collection Wrappers

Rejected. They would change identity, interop, serialization, allocation, and
normal PHP array behavior.

### 16.2 One Undifferentiated Array Contract

Rejected. ++PHP already distinguishes typed lists, typed maps, and broad PHP
arrays; the member type system should preserve that information.

### 16.3 Key-First Callbacks

Rejected. The accepted convention is value first, key second, matching the
chosen value-oriented member naming and modern PHP array callback direction.

### 16.4 `getPath()`

Rejected by RFC 0002. The operation retrieves a value, so the member is `get()`.

### 16.5 Dot Escaping In The Initial Release

Rejected by RFC 0002. Exact segment lists already represent keys containing
dots without introducing an escaping language inside strings.

## 17. Decisions Required Before Acceptance

RFC 0005 cannot be marked Accepted until these details are settled:

```text
1. Final property versus method classification.
2. Strict versus loose contains() comparison.
3. Empty any()/all() behavior.
4. Exact find()/findKey() null behavior and documentation.
5. Complete broad-array contracts.
6. Callback explicit-type requirements.
7. Complete lowering table for PHP 8.4 and later targets.
8. Exact first/last lowering and receiver-evaluation behavior.
9. string[]::join() receiver restrictions.
10. Diagnostic names and source-map rules.
11. Editor presentation of synthetic generic members.
12. Interaction with union, nullable, and broad receivers.
```

RFC 0002 path-access decisions are not reopened by this list.

## 18. Proposed Acceptance Criteria

Once finalized, Stage 15D should prove:

```text
- Lists and maps remain native PHP arrays.
- T[] and array<T> expose identical list members.
- Typed list/map key and value relationships are preserved.
- Every accepted property and method is compiler-checked.
- Callback parameters and results are type-checked.
- Value-first/key-second callback order is consistent.
- List filter reindexes.
- Map filter preserves keys.
- Map map() transforms values and preserves keys.
- reduce() preserves accumulator type.
- get()/hasPath() conform fully to RFC 0002.
- Readonly receivers are not mutated.
- Complex receivers and arguments evaluate once in order.
- Generated PHP contains no synthetic member syntax.
- No wrappers or ++PHP runtime dependency are introduced.
- Generated output preserves PHP runtime behavior.
- Source maps and diagnostics point to original member expressions.
- Prior stages remain green.
```
