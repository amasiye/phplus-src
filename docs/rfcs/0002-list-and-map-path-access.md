# RFC 0002 — List And Map Path Access

```text
Status: Accepted
Implementation: Scheduled For Stage 15D
```

This RFC settles safe direct and nested lookup for the post-MVP **List And Map
Objects** feature. It does not implement the synthetic members or their
lowering.

## 1. Motivation

PHP array access is concise when every key is written directly in source:

```php
$port = $configuration['database']['port'] ?? 3306;
```

It becomes repetitive when the location is dynamic. Developers must traverse
segments, verify every intermediate value is an array, distinguish a missing
key from a present `null` value, and apply defaults consistently.

++PHP will provide compiler-owned synthetic array members for this operation:

```php
$configuration->get('database.port', 3306);
$configuration->hasPath('database.port');
```

The dot-path notation is inspired by `league/config`, whose configuration API
uses forms such as `database.driver`. The lookup and existence semantics adopt
the useful behavior described by PHP's `array_path_get()` and
`array_path_exists()` proposal while presenting it through Stage 15D's native
list and map member model.

## 2. Scope

The accepted members are:

```text
get(location, default = null)
hasPath(location)
```

They apply to:

```text
array<T>
T[]
array<K, V>
array
```

The receiver remains a native PHP array. The feature introduces no wrapper
object, collection base class, runtime registry, or ++PHP runtime dependency.

## 3. Location Forms

Both members accept one of these location forms:

```text
int
    One direct integer key.

string
    A dot-path. A string containing no dot is a one-segment direct key.

(int|string)[]
    An explicit ordered list of exact path segments.
```

The existing equivalent spelling for the explicit segment list is:

```text
array<int|string>
```

Examples:

```php
array<string, int> $scores = [
    'matt' => 88,
    'mark' => 90,
];

int $score = $scores->get('mark', 0);
bool $hasScore = $scores->hasPath('mark');
```

```php
array $payload = [
    'users' => [
        ['name' => 'Andrew'],
    ],
];

string $name = $payload->get('users.0.name', 'Unknown');
bool $hasName = $payload->hasPath('users.0.name');
```

```php
(int|string)[] $path = ['users', 0, 'name'];

string $name = $payload->get($path, 'Unknown');
```

An integer or a string without a dot is a one-segment path. These are therefore
equivalent for the same direct location:

```php
$array->get('name');
$array->get(['name']);
```

## 4. Dot-Path Notation

A dot separates nested array-key segments:

```text
database.port
users.0.name
services.mail.host
```

Stage 15D supports **dot notation only** as the human-written shorthand. Slash
notation is not an equivalent spelling.

The first release has no escape syntax inside a dot path. To address a literal
key containing a dot, use an explicit segment list:

```php
array<string, string> $metadata = [
    'build.version' => '2026.3.1',
];

string $version = $metadata->get(['build.version'], 'unknown');
```

The distinction is deliberate:

```php
$metadata->get('build.version');
// Traverses ['build', 'version'].

$metadata->get(['build.version']);
// Reads the exact top-level key 'build.version'.
```

Every dot is a separator. Explicit segment lists remain the exact form for
keys containing dots, dynamically assembled paths, and paths whose segment
boundaries should not be encoded in one string.

PHP's observable array-key behavior remains authoritative. In particular,
numeric path segments interact with integer array keys according to PHP's array
key rules, allowing paths such as `users.0.name` to traverse lists naturally.
The compiler's static path analysis must model the same behavior.

## 5. `get()` Semantics

Conceptually:

```text
get(int|string|(int|string)[] location, mixed default = null): mixed
```

`get()` retrieves the value at the supplied location.

Rules:

```text
1. Apply each path segment in order.
2. Use array-key existence semantics, not isset semantics.
3. If a segment is missing, return the default.
4. If a non-array value is reached before traversal is complete, return the
   default.
5. If the final key exists, return its value, including null.
6. Produce no missing-key warning or notice.
7. An omitted default is null.
```

Examples:

```php
array $configuration = [
    'database' => [
        'password' => null,
    ],
];

mixed $password = $configuration->get(
    'database.password',
    'secret',
);
// null: the key exists.
```

```php
array $configuration = [
    'database' => 'mysql',
];

string $host = $configuration->get(
    'database.host',
    'localhost',
);
// localhost: an intermediate value is not an array.
```

An empty explicit path identifies the receiver:

```php
array $same = $array->get([]);
```

An empty string is a one-segment direct empty-string key rather than the empty
path.

## 6. `hasPath()` Semantics

Conceptually:

```text
hasPath(int|string|(int|string)[] location): bool
```

`hasPath()` reports whether the complete location exists.

Rules:

```text
1. Apply each path segment in order.
2. Use array_key_exists-style existence semantics.
3. Return false when any segment is missing.
4. Return false when a non-array value is reached before traversal completes.
5. Return true when the final key exists, even when its value is null.
6. Produce no missing-key warning or notice.
```

An empty explicit path identifies the receiver and therefore exists:

```php
$array->hasPath([]); // true
```

## 7. Relationship To Other Stage 15D Members

The member names have distinct responsibilities:

```text
contains(value)
    Whether the array contains a value.

containsKey(key)
    Whether one exact direct key exists. Dots have no path meaning.

hasPath(location)
    Whether the complete direct or nested location exists.

get(location, default = null)
    Retrieve the value at the direct or nested location.
```

Example:

```php
array $values = [
    'database.port' => 3306,
    'database' => [
        'port' => 5432,
    ],
];

$values->containsKey('database.port'); // true
$values->hasPath('database.port');     // true
$values->get('database.port');         // 5432
$values->get(['database.port']);       // 3306
```

`getPath()` is not part of the API. `get()` describes the result being
requested: the value at the supplied location.

## 8. Path Validation

Every explicit path-list segment must be an `int` or `string`.

When an invalid segment is statically visible, the compiler reports the normal
argument/type diagnostic.

When a broad or dynamic boundary prevents compile-time proof, generated PHP
validates **all** explicit segments before traversal and throws `TypeError` if
any segment is neither `int` nor `string`. Validation does not stop merely
because an earlier valid segment would already make traversal fail.

A direct location must likewise be an integer, string, or valid explicit
segment list. Dot-path strings need no segment-type validation because every
split segment is a string.

`TypeError` remains part of PHP's unchecked `Error` hierarchy and introduces no
checked `throws` obligation.

## 9. Static Types

The compiler returns the narrowest type it can prove.

### 9.1 Direct List Lookup

```php
string[] $names = ['matt', 'mark'];

?string $name = $names->get(1);
string $name = $names->get(1, 'unknown');
```

Conceptually:

```text
T[]::get(int): T|null
T[]::get<D>(int, D): T|D
```

A statically known incompatible direct key is rejected:

```php
$names->get('first');
// Error: a typed list requires an integer-compatible direct key.
```

### 9.2 Direct Map Lookup

```php
array<string, int> $scores = ['mark' => 90];

?int $score = $scores->get('mark');
int $score = $scores->get('mark', 0);
```

Conceptually:

```text
array<K, V>::get(K): V|null
array<K, V>::get<D>(K, D): V|D
```

### 9.3 Statically Known Nested Path

For a literal dot path or literal explicit segment list, the compiler traverses
the structured list/map types when it can do so soundly:

```php
array<string, array<string, int>> $configuration = [
    'database' => [
        'port' => 3306,
    ],
];

?int $port = $configuration->get('database.port');
int $port = $configuration->get('database.port', 3306);
```

The result contract is:

```text
Known leaf P without an explicit default:
    P|null

Known leaf P with default D:
    P|D
```

Normal union canonicalization applies.

### 9.4 Dynamic Path

When the path shape is not statically knowable, the compiler retains the
narrowest sound type. It uses `mixed` when no narrower result can be proven:

```php
string $path = determinePath();
mixed $value = $configuration->get($path);
```

The compiler must not fabricate path-dependent precision.

`hasPath()` always returns `bool`.

## 10. Evaluation Order

Lowering must evaluate, in this order:

```text
1. Receiver
2. Location
3. Explicit default, when supplied
```

Each is evaluated exactly once.

The default is eager, like an ordinary argument. It is evaluated even when the
location exists.

A complex receiver such as:

```php
loadConfiguration()->get(loadPath(), createDefault());
```

must not call any of those expressions more than once.

## 11. Lowering

The compiler lowers the members to ordinary PHP using collision-free temporary
bindings and normal array operations.

Conceptually:

```text
1. Evaluate the receiver, location, and default exactly once.
2. Normalize an integer or one-segment string location into a path.
3. Split a dot-path string on `.`.
4. Validate every explicit segment.
5. Traverse using `is_array()` and `array_key_exists()`.
6. Produce the found value, default, or existence result.
```

Literal dot paths may be split at compile time. Dynamic strings are split at
runtime. The compiler may unroll a statically known path when doing so preserves
identical behavior and source mapping.

Lowering must not use a synthetic closure merely to create an expression. It
should reuse the compiler's prerequisite-statement machinery and preserve
source maps to the original member access.

Generated code contains no synthetic array wrapper and requires no ++PHP
runtime helper.

## 12. Readonly And Mutation

`get()` and `hasPath()` are observational operations. They do not mutate the
receiver and are valid on readonly array bindings and readonly array
properties, subject to ordinary access rules.

This RFC does not define nested path mutation.

## 13. Interoperability

Generated output contains only ordinary PHP arrays and PHP operations.

Native PHP consumers see no new runtime type. PHPDoc emitted for surrounding
list and map declarations continues to use the existing `list<T>` and
`array<K, V>` contracts.

Broad PHP arrays and values crossing a `mixed` boundary retain runtime segment
validation rather than receiving an unsound compile-time guarantee.

## 14. Diagnostics

The initial implementation should reuse stable compiler diagnostics where they
accurately describe:

```text
- Invalid receiver member
- Invalid location argument type
- Invalid typed-list key
- Invalid typed-map key
- Incompatible receiving type
```

A new diagnostic code is justified only when an existing code cannot describe a
user-distinct condition accurately.

All diagnostics point to the original ++PHP member call and preserve the Stage
12 diagnostic architecture.

## 15. Rejected Alternatives

### 15.1 `getPath()`

Rejected because it reads as though the path itself is being retrieved. The
operation retrieves the value found at a location, so `get()` is the clearer
name.

### 15.2 Segment Lists As The Only Notation

Rejected because human-written paths become unnecessarily noisy:

```php
$config->get(['database', 'port'], 3306);
```

Explicit segment lists remain available for exact and dynamic paths, but dot
notation is the concise common form.

### 15.3 Slash Notation

Rejected for the first release. One canonical human-written separator keeps the
language and diagnostics predictable.

### 15.4 Dot Escaping

Rejected for the first release. Exact segment lists already address literal
keys containing dots without introducing an escaping sub-language.

### 15.5 Only Direct-Key `get()`

Rejected because direct PHP array syntax already handles ordinary known keys.
The important value is one operation that also handles dynamic nested paths.

### 15.6 Runtime Collection Wrappers

Rejected because they would add allocation, change PHP array identity and
behavior, and introduce a runtime dependency.

### 15.7 A Compiler Runtime Helper

Rejected. Stage 15D lowers directly to ordinary PHP and must preserve ++PHP's
runtime-free output contract.

## 16. Initial Non-Goals

```text
- Slash-separated paths
- Dot escaping
- Wildcard segments
- Object-property traversal
- ArrayAccess traversal
- Mixed array/object traversal
- setPath()
- removePath()
- requirePath()
- Lazy default callbacks
- User-defined path resolvers
- Nested mutation
```

These require separate proposals if practical use justifies them.

## 17. References

```text
League Config:
    https://config.thephpleague.com/

PHP RFC — array_path_get and array_path_exists:
    https://wiki.php.net/rfc/array_get_and_array_has
```
