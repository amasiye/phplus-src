# Natively Typed Arrays

> **Status:** Implemented in Stage 8.

++PHP provides two erased typed-array forms while retaining bare PHP `array`:

~~~text
array<T>       ordered list of T
array<K, V>    map from K to V
array          broad PHP array
~~~

The generated PHP native type is `array`. PHPDoc preserves `array<T>` as `list<T>` and `array<K, V>` as `array<K, V>`.

## Lists

A list has contiguous integer keys beginning at zero:

~~~php
array<string> $names = ['Matthew', 'Mark'];
$names[] = 'Luke';
$names[0] = 'John';
~~~

String-key writes, noncontiguous literal keys, and `unset($names[0])` are rejected because they break list shape. Values must match the declared element type exactly where the compiler can determine them.

## Maps

A map declares both its key and value:

~~~php
array<string, int> $scores = ['Peter' => 90];
$scores['John'] = 100;
~~~

Keys must use PHP's `int|string` array-key domain. Key and value writes are checked independently. Appending without a key is valid only when the declared key type admits the integer key PHP will create.

Typed arrays are invariant in the MVP. For example, neither `array<Dog>` nor a `Dog` element is widened to `array<Animal>` or `Animal` merely because `Dog` extends `Animal`. Hierarchy-aware collection widening is post-MVP.

## Unpacking

Array unpacking preserves the declared collection contract. A typed list may unpack another compatible typed list. A typed map may unpack a compatible list or map only when the unpacked integer or declared map keys and all values satisfy the target key and value types. Nested typed arrays and generic element construction are validated recursively.

## Nested, Nullable, And Readonly Arrays

Typed arrays compose normally:

~~~php
array<string, array<int>> $groups = ['primary' => [1, 2]];
?array<string, User> $users = null;
~~~

A readonly typed-array local cannot be changed directly or through a nested offset:

~~~php
readonly array<string, array<int>> $groups = ['primary' => [1]];
$groups['primary'][] = 2; // P2006
~~~

## Foreach Contracts

Typed foreach declarations use the collection's exact contract:

~~~php
foreach ($names as int $index => string $name) {
}

foreach ($scores as string $key => int $score) {
}
~~~

A broad native `array` supplies `mixed` keys and values. Invariant element rules also apply to foreach bindings.

## Numeric-String Keys

PHP converts some string keys to integers at runtime. The compiler follows that observable rule for literal keys: `'1'` and `'-1'` are integer keys, while strings such as `'01'` and `'+1'` remain string keys. The exact integer range follows the target PHP runtime.

Computed keys still undergo PHP's runtime coercion. Use an explicit `int|string` map key when either representation is intended.

Typed-array diagnostics use `P3012`–`P3016`.
