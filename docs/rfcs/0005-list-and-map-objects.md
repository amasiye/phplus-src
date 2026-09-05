# RFC 0005 — List And Map Objects

```text
Status: Accepted
Implementation: Scheduled For Stage 15D
Depends On: RFC 0003 — Postfix List Types
Incorporates: RFC 0002 — List And Map Path Access
Shares Synthetic-Member Architecture With: RFC 0004 — Scalar Objects
```

This RFC settles compiler-owned synthetic properties and methods for ++PHP
lists, maps, and broad arrays. Acceptance records the language contract; it
does not implement these members or make them available before Stage 15D.

The feature applies to:

```text
array<T>
T[]
array<K, V>
array
```

[RFC 0002](0002-list-and-map-path-access.md) remains authoritative for `get()`
and `hasPath()`. Their accepted path syntax, traversal, defaults, validation,
and inference are not reopened here.

## 1. Motivation

PHP exposes collection operations primarily through global functions. ++PHP
provides a discoverable, statically typed member surface while retaining
native PHP arrays:

```php
string[] $names = ['Matthew', 'Mark'];

int $count = $names->count;
string $label = $names->join(', ');
string[] $upperNames = $names->map(
    fn (string $name): string => $name->toUpper(),
);
```

Typed lists, typed maps, and broad arrays keep their distinct contracts.
Member lookup, callback validation, generic substitution, checked errors,
source mapping, and lowering are compiler responsibilities.

## 2. Core Architecture

The following rules are normative:

```text
- The receiver remains a native PHP array.
- T[] and array<T> have identical semantics and members.
- No collection wrapper, list/map base class, or runtime member registry exists.
- No separately installed ++PHP runtime package is required.
- Supported members lower to ordinary PHP functions and expressions or ordinary
  control flow using collision-free compiler temporaries.
- Receivers and explicit arguments evaluate exactly once in source order.
- Operations do not mutate the receiver's stored array structure.
- Callback effects and mutable objects are not made pure or deeply immutable.
- Lists preserve list shape; maps retain their key contracts.
- Compiler behavior follows the configured PHP target, not merely the host.
- PHP 8.4 is an initial target, not a permanent platform ceiling.
```

### 2.1 Approved Generated-Support Exception

`CollectionQueryException` is a new ordinary PHP exception required by the
accepted throwing-query behavior. The build emits its support definition into
the deployed application. This is an explicit, narrow extension of the earlier
no-runtime-support wording: there is no external runtime package, but there
is a small generated support artifact.

The exception is not a collection wrapper or a runtime method-dispatch system.
Its deployment and shared identity are part of the Stage 15D acceptance criteria
in Section 8. Implementations must not assume that the compiler package is
installed on the production server.

## 3. Receiver Categories And Naming

### 3.1 Typed Lists

For `T[]` or `array<T>`:

```text
key: int
value: T
shape: list
```

### 3.2 Typed Maps

For `array<K, V>`:

```text
key: K
value: V
shape: map
```

`K` follows the existing PHP array-key and ++PHP typed-map rules. This RFC does
not create new key coercions or change collection invariance.

### 3.3 Broad Arrays

For bare `array`:

```text
key: int|string
value: mixed
shape: not statically known to be a list
```

A broad array is an explicit boundary. Its member contracts must not invent a
narrower value type or list shape without proof.

### 3.4 Names And Member Classification

A name without `Key` concerns values. A `Key` suffix concerns keys. `keys` and
`values` return the corresponding complete collections.

The eight observational members are properties:

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

Operations accepting parameters are methods:

```text
contains()
containsKey()
find()
findKey()
any()
all()
get()
hasPath()
filter()
map()
reduce()
join()
implode()
```

There are no duplicate `first()` or `count()` method spellings. Property syntax
does not imply that an operation cannot throw: `first` and `last` have the
checked effect defined below.

## 4. Observational Properties

### 4.1 List Contracts

```text
T[]::count: int
T[]::isEmpty: bool
T[]::first: T
T[]::firstKey: int|null
T[]::last: T
T[]::lastKey: int|null
T[]::keys: int[]
T[]::values: T[]
```

### 4.2 Map Contracts

```text
array<K, V>::count: int
array<K, V>::isEmpty: bool
array<K, V>::first: V
array<K, V>::firstKey: K|null
array<K, V>::last: V
array<K, V>::lastKey: K|null
array<K, V>::keys: K[]
array<K, V>::values: V[]
```

### 4.3 Broad Array Contracts

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

`mixed` already includes `null`. A broad-array value property therefore needs
no separate nullable wrapper.

### 4.4 First And Last: Throw On Empty

`first` and `last` return the stored first or last value in PHP array iteration
order. They do not sort keys, reindex maps, or modify the internal array pointer.

For every receiver category:

```text
Non-empty array:
    Return the stored value.

Empty array:
    Throw CollectionQueryException.
```

This behavior preserves the declared element result `T` or `V`; absence does
not add a no-value `null` result. If the element type itself admits null, a
stored null is a successful result and must be returned normally.

```php
try {
    string $firstName = $names->first;
} catch (CollectionQueryException $error) {
    // The receiver was empty.
}
```

The short exception name in examples refers to the shared support declaration
described in Section 8; it is not a per-application user-defined replacement.

Both properties contribute `CollectionQueryException` to checked-error
analysis whenever the empty case is possible. A property read is not exempt
from catch-or-declare rules. Existing sound non-emptiness facts may discharge
that outcome, but this RFC does not introduce a new non-empty-list type or
require a new refinement syntax.

`firstKey` and `lastKey` deliberately remain nullable on empty arrays. They do
not throw this query exception. On a list their keys are integers; on a map
they retain `K`.

### 4.5 Keys, Values, And Readonly

`keys` returns a list of keys in iteration order. `values` returns a reindexed
list of values in iteration order, including for broad arrays.

The canonical `values` lowering is `array_values()`. An implementation may
optimize a proven list case only when ordinary PHP value, reference, and
observable behavior are preserved.

A readonly receiver does not make the returned list binding readonly.
Readonly remains a property of the receiving declaration. No deep copying or
freezing of contained objects is implied.

## 5. Direct Membership Queries

### 5.1 contains()

```text
T[]::contains(T $value): bool
array<K, V>::contains(V $value): bool
array::contains(mixed $value): bool
```

Comparison is strict, corresponding to:

```php
\in_array($value, $receiver, true)
```

There is no loose-comparison flag in the initial API. PHP's strict comparison
behavior remains authoritative; the compiler does not introduce custom object
value equality.

### 5.2 containsKey()

```text
T[]::containsKey(int $key): bool
array<K, V>::containsKey(K $key): bool
array::containsKey(int|string $key): bool
```

This checks one direct key with `array_key_exists()` semantics. A present key
holding null exists. Dots have no special meaning here:

```php
$configuration->containsKey('database.port');
// Tests the exact top-level key, not a nested path.
```

RFC 0002 supplies `hasPath()` for path existence.

## 6. find() And findKey()

### 6.1 Public Forms

Both methods take a predicate followed by an optional options bitmask:

```text
find($predicate, int $findOptions = 0)
findKey($predicate, int $findOptions = 0)
```

The parameter name `findOptions` is part of the named-argument contract.
Callbacks receive value first and, when declared, key second.

With no throwing flag, descriptive contracts are:

```text
T[]::find(callable(T, int): bool $predicate, int $findOptions = 0): T|null
T[]::findKey(callable(T, int): bool $predicate, int $findOptions = 0): int|null

array<K, V>::find(callable(V, K): bool $predicate, int $findOptions = 0): V|null
array<K, V>::findKey(callable(V, K): bool $predicate, int $findOptions = 0): K|null

array::find(callable(mixed, int|string): bool $predicate, int $findOptions = 0): mixed
array::findKey(callable(mixed, int|string): bool $predicate, int $findOptions = 0): int|string|null
```

Callable signatures here describe supplied argument contracts. They do not
require a callback to declare the unused trailing key parameter; see Section 11.

### 6.2 FindOptions

The named flag holder is `FindOptions`, with exactly these initial constants:

```php
public const int NONE = 0;
public const int THROW_ON_NOT_FOUND = 1 << 0;
```

Flags are combined with bitwise OR and tested with bitwise AND. No unused flags
are reserved as active behavior.

The compiler's synthetic declaration catalog must publish one canonical
qualified identity for this holder and use it consistently in source lookup,
imports, diagnostics, and editor metadata. Examples use its short imported name.
Constants may lower to integer values; any reference that survives lowering
must have a valid deployed definition. No emitted code may depend on finding
`FindOptions` in the compiler's production-absent autoloader.

### 6.3 Options Validation

Only non-negative masks consisting of supported bits are valid. Initially,
valid values are `0` and `FindOptions::THROW_ON_NOT_FOUND`.

A statically provable invalid mask receives a compiler diagnostic. A dynamic
invalid mask throws `ValueError` before invoking the predicate, including for
an empty receiver. Error guidance identifies unsupported bits or a negative
mask without exposing generated variable names.

Receiver and explicit arguments are still evaluated once in source order before
call-body validation. A nullsafe access whose receiver is null skips its
arguments, options validation, and traversal.

### 6.4 Match Semantics

Each query visits entries in iteration order and stops at the first predicate
result equal to the required boolean true. The predicate is not evaluated again
for the selected entry.

```text
find():
    Return the matched value.

findKey():
    Return the matched key.
```

A matched `null`, `false`, `0`, or empty string is a successful match. The result's
truthiness must never be used to decide whether a match occurred.

On no match:

```text
Throwing bit absent:
    Return null.

Throwing bit present:
    Throw CollectionQueryException.
```

An empty array is a no-match case. A valid query on it invokes the predicate
zero times.

### 6.5 Flag-Sensitive Results And Effects

Let `V` be the receiver's value type and `K` its key type:

| Compile-time knowledge | find() result | findKey() result | Lookup-failure effect |
| --- | --- | --- | --- |
| Throwing bit absent | V\|null | K\|null | None |
| Throwing bit present | V | K | CollectionQueryException |
| Throwing bit unknown | V\|null | K\|null | Possible CollectionQueryException |

These effects are additional to the predicate's existing checked-error
contract. Invalid-mask `ValueError` is an unchecked PHP Error descendant.

Enabling the flag removes only the no-match null outcome. It does not remove
null from an element type that permits null. Broad-array `find()` remains
`mixed` even with the flag; broad-array `findKey()` narrows to `int|string`.

```php
function requireUser(User[] $users, int $id): User
    throws CollectionQueryException
{
    return $users->find(
        fn (User $user): bool => $user->id === $id,
        findOptions: FindOptions::THROW_ON_NOT_FOUND,
    );
}
```

No flag changes `get()`, `hasPath()`, `first`, or `last`.

## 7. any(), all(), get(), And hasPath()

### 7.1 Predicate Queries

```text
T[]::any(callable(T, int): bool $predicate): bool
T[]::all(callable(T, int): bool $predicate): bool

array<K, V>::any(callable(V, K): bool $predicate): bool
array<K, V>::all(callable(V, K): bool $predicate): bool

array::any(callable(mixed, int|string): bool $predicate): bool
array::all(callable(mixed, int|string): bool $predicate): bool
```

`any()` stops on the first true predicate result. `all()` stops on the first
false result. Neither visits later entries after the outcome is established.

```text
Empty any(): false
Empty all(): true
```

Neither invokes the predicate on an empty receiver. These methods do not add
`CollectionQueryException`; ordinary callback effects still apply.

### 7.2 Path Access

RFC 0002 remains authoritative:

```php
$configuration->get('database.port', 3306);
$configuration->hasPath('database.port');
$metadata->get(['build.version'], 'unknown');
```

Both members accept an integer location, a dot-path string, or an exact
`(int|string)[]` segment list. Missing segments and non-array intermediates
produce the default or false. A final null is present. An empty explicit path
identifies the receiver. The default is eager; receiver, location, and default
are evaluated once in order.

This RFC adds no query flags or query exceptions to path lookup. It does not
rename `get()` to `getPath()`, add slash notation, or add dot escaping.

## 8. CollectionQueryException And Deployment

### 8.1 Exception Contract

`CollectionQueryException` extends PHP's `RuntimeException`. It is an ordinary
checked exception under ++PHP's existing Exception-versus-Error rules.

It is produced by:

```text
- first on an empty array
- last on an empty array
- find with THROW_ON_NOT_FOUND when no entry matches
- findKey with THROW_ON_NOT_FOUND when no entry matches
```

Messages must identify whether the operation read an empty collection or failed
to find a match. Do not include collection contents, secrets, generated
identifiers, or backend paths in those messages.

Predicate, mapper, reducer, and other user-code exceptions are not wrapped as
`CollectionQueryException`; they propagate with their own type and contract.

### 8.2 Shared Generated Definition

The build must emit a shared ordinary PHP definition with one stable fully
qualified identity in a reserved namespace. That identity must be exposed by
the compiler's source declaration catalog so source imports, catches, throws
clauses, generated PHPDoc, tooling, and emitted PHP all refer to the same type.

The canonical fully qualified names and physical metadata layout must be
recorded centrally by the implementation, not invented separately by each
backend, package, or generated source file. The public simple names settled
here are `CollectionQueryException` and `FindOptions`.

The support contract requires:

```text
- One shared exception identity across application and compiled dependencies.
- No per-source-file or per-package alternative exception class.
- Normal autoload/bootstrap integration before a generated throwing use.
- No production dependency on the ++PHP compiler installation.
- Support files tracked by build manifests, source maps where applicable,
  incremental-cache identities, and atomic output transactions.
- Source-free deployment includes the necessary support artifacts.
- Multiple compiled packages coexist without class redeclaration.
- Incompatible support definitions are diagnosed, not selected accidentally by
  load order.
- No runtime collection wrapper or synthetic-member dispatcher.
```

The small generated support allowance is approved. It is not permission to add
a separately installed runtime library or execute application code while
compiling. Stage 15D must demonstrate cross-package composition before claiming
this deployment contract complete.

### 8.3 Checked Property And Method Effects

Checked-error analysis must understand effects directly from the synthetic
member contract. It must not wait to discover a generated throw downstream.

`first` and `last` reads contribute their possible exception. `find()` and
`findKey()` contribute flag-sensitive lookup effects plus predicate effects.
Other callback operations preserve known callable effects. Existing rules for
anonymous-callable boundaries and imported PHP/PHPDoc/stub contracts remain
unchanged; this RFC does not invent closure `throws` syntax.

## 9. Non-Mutating Transformations

### 9.1 filter()

```text
T[]::filter(callable(T, int): bool $predicate): T[]
array<K, V>::filter(callable(V, K): bool $predicate): array<K, V>
array::filter(callable(mixed, int|string): bool $predicate): array
```

Lists are reindexed after filtering. Maps preserve original keys. Broad arrays
also preserve keys because the static contract does not establish list shape.
Entries retain iteration order. An empty receiver returns an empty array and
invokes the predicate zero times.

### 9.2 map()

```text
T[]::map<U>(callable(T, int): U $mapper): U[]
array<K, V>::map<U>(callable(V, K): U $mapper): array<K, U>
array::map<U>(callable(mixed, int|string): U $mapper): array<int|string, U>
```

The mapper transforms values, not keys. Lists produce lists. Maps and broad
arrays preserve keys and iteration order. The mapper's declared result type
provides `U`. These descriptive generic signatures do not introduce explicit
generic call-site syntax.

An empty receiver returns an empty array without invoking the mapper.
`mapKeys()` remains deferred pending a separate collision policy.

### 9.3 reduce()

```text
T[]::reduce<U>(callable(U, T, int): U $reducer, U $initial): U
array<K, V>::reduce<U>(callable(U, V, K): U $reducer, U $initial): U
array::reduce<U>(callable(U, mixed, int|string): U $reducer, U $initial): U
```

Reducers receive accumulator, value, then optional key. An explicit initial
value is required. The initial value and every reducer result must satisfy the
accumulator contract. An empty receiver returns the initial value without
invoking the reducer. No omitted-initial-value overload exists initially.

## 10. join() And implode()

Both names are accepted:

```text
join(string $separator): string
implode(string $separator): string
```

Both are available on lists, maps, and explicit broad arrays. For a statically
typed collection, the supported element domain is:

```text
string|int|float|bool|null|Stringable
```

Here `Stringable` means PHP's global `\Stringable` contract. Every statically
possible element must be supported. Provably unsuitable typed elements, such
as nested arrays or non-stringable objects, receive a compiler diagnostic.

An explicit broad array retains the native PHP boundary when its values are
not known. The compiler must not claim that such joining is warning-free or
error-free and must not fabricate a narrow element contract.

```php
string[] $names = ['Matthew', 'Mark'];

string $joined = $names->join(', ');
string $imploded = $names->implode(', ');
```

The requested function spelling is preserved:

```php
$joined = \join(', ', $names);
$imploded = \implode(', ', $names);
```

Values are joined in iteration order; keys are not included. An empty array
returns an empty string. Conversion uses native PHP behavior, including
boolean and null string conversion, rather than bool's optional human-readable
Scalar Object conversion.

This is an explicit string-producing operation, not an implicit-coercion rule
for ordinary assignments. Known effects of string conversion must not be
silently erased. No separator-free overload, array-of-arrays flattening, or
resource-specific typed conversion is introduced here.

## 11. Callback Typing And Invocation

### 11.1 Explicit Source Types

Every parameter actually declared by a `.ppphp` callback requires an explicit
type. The return type is also explicit. Contextual types validate declarations;
they do not create an exception to ++PHP's explicit declaration policy.

Predicates for `find`, `findKey`, `any`, `all`, and `filter` return `bool`, not an
arbitrary truthy value. A mapper supplies a declared result type. A reducer
supplies a declared accumulator-compatible result type.

### 11.2 Unused Trailing Keys

Both forms are valid:

```php
$names->map(fn (string $name): string => $name->toUpper());
$names->map(fn (string $name, int $key): string => $name->toUpper());
```

A one-parameter query/mapper callback is invoked with value only. A two-parameter
form receives value and key. A reducer may omit its trailing key parameter and
receive accumulator and value only. Invocation must use the resolved callable
contract, including existing default and variadic rules, rather than relying
on excess arguments being ignored.

Named callables and native PHP callables receive the same arity-aware behavior.
If a callable's signature cannot be established, use the existing conservative
callable-boundary rules; do not invent a safe arity or perform runtime reflection
as a replacement for compiler-owned resolution.

### 11.3 Compatibility

A callback parameter must safely accept every value supplied in that position.
It need not be textually identical to the collection's type:

```text
Broader value parameter:
    Allowed when ordinary callable compatibility proves it safe.

Narrower value parameter:
    Rejected when the collection could supply a different value.

Key parameter:
    Must accept the collection's key type whenever declared.
```

For broad arrays, the normal explicit contracts are `mixed` for values and
`int|string` for keys. Existing nominal inheritance and callable compatibility
rules apply; this does not relax collection invariance.

No by-reference value or key parameters are added by this non-mutating API.
Imported `.php` callables are checked through their available native, PHPDoc,
and stub contracts; `.ppphp` syntax requirements are not imposed on PHP source.

## 12. Readonly, Union, Nullable, And Broad Receivers

### 12.1 Readonly

All initial operations are available on readonly array bindings because they
do not themselves mutate the receiver's stored array structure. Returned arrays
follow normal value and receiving-binding rules. Contained objects, aliases,
and callbacks retain their ordinary PHP effects; the API promises no deep
immutability or callback purity.

### 12.2 Unions

Resolve a synthetic member independently for every reachable receiver arm.
The operation must be valid for every arm; callback parameter types must safely
accept every possible supplied value and key. Result types and checked effects
are combined canonically across those arms.

A supported operation on one arm must not make an unsupported arm disappear.
For example, a typed union of joinable and non-joinable collections is not
accepted merely because one arm supports joining.

### 12.3 Nullability

Nullable receivers require sound narrowing or nullsafe access:

```php
?string[] $names = loadNames();
?int $count = $names?->count;
```

When nullsafe access is skipped, arguments, flags, and callbacks are not
evaluated. The result includes null. A non-null empty receiver still follows
the operation's empty-array rule; nullsafe `first` is not an exemption from
throw-on-empty behavior.

### 12.4 Broad And mixed

Bare `array` receives the broad contracts defined in this RFC. `mixed` must be
narrowed to an array before this statically resolved member API can be used.
Do not conflate a known array with an arbitrary unknown value.

## 13. Evaluation And Lowering

### 13.1 Evaluation Order

The source-level contract explicitly requires evaluation of the receiver once,
then explicit argument expressions once in source order. Ordinary arguments
are eager; nullsafe short-circuiting is the stated exception.

Lowering must preserve that contract even when native function parameter order
differs from member-call order:

```php
loadNames()->join(loadSeparator());
```

Evaluate `loadNames()` before `loadSeparator()`, then call native `join()` with
separator first and the evaluated array second. Do not reverse observable
source evaluation merely by rewriting argument order.

Prerequisites must remain inside the original conditional execution region.
Do not hoist work from short-circuit expressions, nullsafe accesses, ternary
arms, or `when` branches into unconditional execution.

### 13.2 Complete Lowering Table

`$r` below denotes the receiver after any required once-only evaluation. Native
functions are fully qualified so namespace functions cannot shadow the intended
lowering.

| Member | PHP 8.4 lowering | Later supported targets |
| --- | --- | --- |
| count | `\count($r)` | Same contract |
| isEmpty | `$r === []` | Same contract |
| first | Empty guard, then `\array_key_first($r)` and indexed read | PHP 8.5+ may use `\array_first($r)` after the same empty guard |
| last | Empty guard, then `\array_key_last($r)` and indexed read | PHP 8.5+ may use `\array_last($r)` after the same empty guard |
| firstKey | `\array_key_first($r)` | Same contract |
| lastKey | `\array_key_last($r)` | Same contract |
| keys | `\array_keys($r)` | Same contract |
| values | `\array_values($r)` | Same contract |
| contains | `\in_array($value, $r, true)` | Same strict contract |
| containsKey | `\array_key_exists($key, $r)` | Same exact-key contract |
| find | Validate flags; one short-circuiting traversal with explicit found state and captured value | Native queries only when null, flag, arity, and effect semantics are equivalent |
| findKey | Validate flags; one short-circuiting traversal with explicit found state and captured key | Native queries only when all accepted semantics are equivalent |
| any | Arity-aware `\array_any()` where equivalent, otherwise short-circuiting traversal | Same empty and short-circuit contract |
| all | Arity-aware `\array_all()` where equivalent, otherwise short-circuiting traversal | Same empty and short-circuit contract |
| List filter | Key-aware `\array_filter()` followed by `\array_values()`, or equivalent arity-aware traversal appending retained values | Same list shape and callback contract |
| Map/broad filter | Key-aware filtering preserving keys, or equivalent arity-aware traversal | Same key-preservation contract |
| List map | Ordered traversal invoking value/optional-key mapper and appending results | Native implementation only if the full contract is preserved |
| Map/broad map | Ordered traversal invoking mapper and assigning each result at the original key | Same key-preservation contract |
| reduce | Ordered accumulator traversal, invoking accumulator/value/optional-key reducer | Same initial-value and invocation contract |
| join | `\join($separator, $r)` after source-order argument evaluation | Preserve the join spelling and native contract |
| implode | `\implode($separator, $r)` after source-order argument evaluation | Preserve the implode spelling and native contract |
| get | RFC 0002 normalization, full path validation, and traversal | RFC 0002 remains authoritative |
| hasPath | RFC 0002 normalization, full path validation, and traversal | RFC 0002 remains authoritative |

The compiler must never emit a native function unsupported by the configured
target. It may adopt newer target facilities without changing these source
contracts. This is not a permanent PHP 8.4 restriction.

### 13.3 First/Last Guards

The empty guard distinguishes an empty receiver from a receiver whose selected
value is null. `isset()` or null-coalescing the selected value cannot implement
that distinction alone.

Native `array_first()` and `array_last()` are not unguarded aliases here: the
synthetic properties intentionally throw on empty rather than return the
native absence sentinel. Earlier targets implement the same behavior through
key lookup. Receiver evaluation occurs only once in either case.

### 13.4 Callback And Match Preservation

A lowering must not scan twice to distinguish a found null from no result.
Capture found state, value, and key during the same traversal. Stop immediately
when the operation has its result.

Do not blindly lower `map()` to `array_map()` or `reduce()` to `array_reduce()`:
the accepted API supplies a key when requested, which those direct rewrites do
not themselves provide. Likewise, a native predicate function is usable only
when resolved callback arity and all required effects are preserved.

## 14. Static Results And Editor Presentation

Members preserve structured types rather than reducing every operation to
bare `array` or `mixed`:

```text
string[]::filter(...)                 -> string[]
string[]::map(callback returning int) -> int[]
array<string, int>::filter(...)       -> array<string, int>
array<string, int>::map(returning U)  -> array<string, U>
array<string, int>::keys              -> string[]
array<string, int>::values            -> int[]
```

`find()` and `findKey()` apply the options-sensitive contracts in Section 6.
RFC 0002 governs literal path-sensitive `get()` inference.

Editors should display:

```text
- Receiver-specialized generic types
- Property versus method classification
- Accepted parameter names and defaults
- Contextual callback parameter and result contracts
- The optional trailing key parameter
- Flag-sensitive return types
- Checked effects, including throwing properties
- Platform-target information where relevant
```

Synthetic member definitions should navigate to compiler-provided declaration
or documentation views, not fictitious runtime collection classes. The real
generated exception has its own valid declaration identity; it is not evidence
that scalar or array receivers are boxed.

## 15. Diagnostics And Source Maps

Use the existing compiler diagnostic catalog and source-mapping infrastructure.
Unknown members, incompatible receivers, wrong argument types, callback
incompatibility, and unhandled checked effects use existing accurate families.
Add a distinct option-value diagnostic only if no existing code expresses the
condition correctly; do not freeze invented diagnostic numbers in this RFC.

Locations must identify:

```text
- The original member for member lookup failures.
- The original argument for type or option failures.
- The callback parameter or return declaration for contract incompatibility.
- The originating query or property access for unhandled checked effects.
```

Generated guards, loops, temporaries, and support references map back to their
original source owner. Generated names, backend paths, or library internals
must not replace actionable source locations in normal diagnostics.

## 16. Dynamic Access, Runtime Identity, And Deferred Features

Synthetic collection members do not become methods on a PHP object. Ordinary
PHP reflection cannot enumerate them on an array.

Computed member names are not supported by this synthetic API:

```php
string $method = 'filter';
$items->{$method}($predicate);
```

Existing dynamic-boundary rules apply; this RFC adds no runtime dispatcher.

The initial feature excludes:

```text
push, pop, shift, unshift
sort, reverseInPlace
insert, remove
mapKeys
setPath, removePath
runtime collection wrappers
user-defined collection prototypes
implicit callback type declarations
explicit generic call-site syntax
new closure throws syntax
```

Mutation-oriented methods need a separate contract for lvalues, references,
readonly storage, key preservation, sorting behavior, and fluent mutation.

## 17. Accepted Decisions

The earlier open-decision list is replaced by this accepted record:

| Decision | Accepted outcome |
| --- | --- |
| Observations | Eight properties; parameterized operations remain methods |
| contains comparison | Strict |
| Empty any/all | false / true |
| Query options | `findOptions` bitmask with NONE and THROW_ON_NOT_FOUND |
| Invalid options | Compile-time diagnostic when known; dynamic ValueError before callback invocation |
| Found null | Successful result, never confused with no match |
| Query result typing | Flag-sensitive result and checked-error contracts |
| Empty first/last | Throw CollectionQueryException; successful result is T/V/mixed |
| Empty firstKey/lastKey | Return null |
| Exception hierarchy | Ordinary checked RuntimeException descendant |
| Exception deployment | Shared build-emitted definition; no required external runtime package |
| Callback declarations | Explicit types, compatible signatures, optional unused trailing key |
| Broad arrays | Broad properties and methods without fabricated list/value precision |
| Joining | Both join and implode; lists/maps/broad arrays; typed string-convertible values |
| Target lowering | Complete target-aware table; PHP support may advance |
| Nullable/union/readonly receivers | Sound per-arm resolution, nullsafe skipping, no receiver mutation |
| Diagnostics and editors | Source-owned diagnostics and specialized synthetic declarations |
| Path access | Accepted RFC 0002 unchanged |

## 18. Implementation Acceptance Criteria

Stage 15D must prove:

```text
- Lists, maps, and broad arrays remain native PHP arrays.
- T[] and array<T> expose identical member contracts.
- All eight observational members are properties.
- first/last return the declared element type and throw on an empty receiver.
- A stored null is returned normally by first/last and a successful find.
- firstKey/lastKey remain nullable without throwing on emptiness.
- contains is strict; containsKey tests exact keys, including present null.
- FindOptions exposes only the accepted initial flags.
- Invalid flags fail before any predicate invocation.
- find/findKey invoke each visited predicate at most once and stop on match.
- THROW_ON_NOT_FOUND throws only when no entry matched.
- Flag-sensitive return types and checked effects are compiler-owned.
- Predicate exceptions are propagated unchanged.
- CollectionQueryException is catchable through one shared stable identity.
- A source-free deployment works without the compiler package installed.
- An application and two compiled packages share exception support without
  redeclaration or load-order-dependent incompatible definitions.
- Generated support participates in manifests, caching, autoloading, and atomic
  complete/focused build behavior.
- Empty any is false; empty all is true; neither invokes the predicate.
- Explicit callback types remain required in .ppphp.
- Unused trailing key parameters may be omitted.
- Native and named callable arity is respected without relying on ignored
  surplus arguments.
- Callback input compatibility and output types remain sound.
- List filter reindexes; map/broad filter preserves keys.
- List map produces a list; map/broad map preserves keys and result value types.
- reduce requires its initial value and preserves its accumulator contract.
- join and implode both exist and lower to their corresponding native names.
- Typed joining accepts the approved string-convertible element domain and
  rejects provably unsuitable typed elements.
- Broad joining retains its documented native PHP boundary.
- Both joining aliases return an empty string for empty arrays.
- get/hasPath conform completely to accepted RFC 0002.
- Readonly receivers are not structurally mutated by these members.
- Nullable access skips arguments and callbacks when the receiver is null.
- Union receivers and callback contracts are sound for every possible arm.
- Receiver and explicit arguments evaluate once in source order, including
  native calls whose parameter order differs from source member syntax.
- Prerequisite statements remain inside the correct conditional region.
- First/last lower correctly on PHP 8.4 and on supported later targets with
  native array_first/array_last; no unavailable target function is emitted.
- No synthetic array-member syntax remains in generated PHP.
- Source maps and checked-effect diagnostics refer to original expressions.
- Editor presentation includes generic substitution, options, and effects.
- No collection wrappers, runtime dispatcher, or external runtime package is
  introduced.
- All prior language, analysis, interoperability, and build guarantees remain
  green.
```
