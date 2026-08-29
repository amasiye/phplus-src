# ++PHP Language Overview

> **Status:** Typed locals, typed loop bindings, strict project-wide types, and checked errors are active. Generics, typed arrays, and when are parsed but inactive.

++PHP is a PHP-shaped source language that adds compile-time validation and erasable features while preserving PHP runtime behavior. .ppp files use the normal PHP opening tag and compile to ordinary .php files. Ordinary .php files may coexist in the same project and are never rewritten.

## Active Local Bindings

A local declaration writes its type before its variable and always includes an initializer:

~~~php
string $name = 'Andrew';
int $attempts = 0;
?int $result = null;
mixed $value = loadValue();
readonly array $items = [];
~~~

Declarations are mutable unless prefixed with readonly. Bare assignment cannot introduce a local, and val and var are not declaration keywords.

A mutable binding keeps its declared type for its lifetime. A readonly binding may be read but cannot be reassigned, unset, referenced, or structurally mutated through that storage location. Readonly does not recursively freeze an object:

~~~php
readonly User $user = new User('Andrew');

$user->rename('Lucy'); // allowed by the local-binding rule
$user = new User('Lucy'); // rejected
~~~

Callable parameters, catch variables, $this, native property-hook bindings, and superglobals are recognized existing bindings. Bare foreach and destructuring targets must already refer to mutable locals. Global and static local declarations are unsupported in .ppp files.

Loop headers may declare bindings explicitly:

~~~php
for (int $index = 0; $index < 10; ++$index) {
}

foreach ($names as string $name) {
}

foreach ($scores as string $key => int $score) {
}
~~~

Loop declarations use the enclosing PHP-compatible variable scope. A foreach binding may remain uninitialized when the loop executes zero times.

Stage 5 checks definitive literal and local-to-local type relationships. Stage 6 checks calls, returns, members, properties, symbols, nullability, PHPDoc, and valid cross-file context through the project analyzer.

## Strict .ppp Declarations

Every .ppp parameter and property requires a native type. Every .ppp callable requires a native return type except `__construct` and `__destruct`. Explicit `mixed`, `array`, `object`, `callable`, and `iterable` are valid deliberate choices. Equivalent omissions in ordinary .php retain PHP behavior.

.ppp also rejects eval, variable variables, runtime-dependent include paths, return-by-reference declarations, and dynamic property creation. Static paths composed from literals, `__DIR__`, `__FILE__`, and concatenation remain valid.

## Checked Errors

Named functions, methods, constructors, interface methods, and abstract methods may declare checked errors:

~~~php
function loadUser(string $id): User throws UserNotFound, StorageFailure
{
}
~~~

A checked exception that can escape a callable must be caught or declared. Resolved calls contribute their declared error contracts, catches remove matching types and subtypes, and overrides may narrow but not widen inherited contracts. PHP Error descendants remain unchecked. File scope, closures, arrow functions, and destructors cannot declare or leak checked errors.

Ordinary PHP and configured stubs may contribute @throws metadata at interoperability boundaries. A dynamic call that cannot be resolved produces a P4005 warning because the compiler cannot establish a checked-error guarantee.

## Inactive Syntax

The frontend records exact nodes and source spans for the remaining MVP syntax:

- generic declarations and references;
- array<T> and array<K, V>; and
- value-producing when expressions.

These forms report P3001 or P5001 and block a build. They are never emitted as placeholder runtime behavior.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, or a new object model. See [typed local bindings](typed-local-bindings.md), [typed loop bindings](typed-loop-bindings.md), and [checked errors](checked-errors.md) for the active rules.
