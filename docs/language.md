# ++PHP Language Overview

> **Status:** Typed locals, typed loop bindings, strict project-wide types, checked errors, composite types, erased generics, typed arrays, expression-oriented `when`, and complete structured generic context are active.

++PHP is a PHP-shaped source language that adds compile-time validation and erasable features while preserving PHP runtime behavior. .ppphp files use the normal PHP opening tag and compile to ordinary .php files. Ordinary .php files may coexist in the same project and are never rewritten.

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

Callable parameters, catch variables, $this, native property-hook bindings, and superglobals are recognized existing bindings. Bare foreach and destructuring targets must already refer to mutable locals. Global and static local declarations are unsupported in .ppphp files.

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

Local checks cover definitive literal and local-to-local relationships. Project analysis checks calls, returns, members, properties, symbols, nullability, PHPDoc, generic substitution, and valid cross-file context.

## Composite And Generic Types

Union, intersection, and DNF forms are validated and compared canonically:

~~~php
int|string $identifier = 1;
Countable&Iterator $records = new RecordSet();
(Countable&Iterator)|array $source = [];
~~~

Generic declarations are available on classes, interfaces, traits, functions, and methods. References must supply the correct invariant arguments and satisfy declared bounds:

~~~php
class Box<T : Entity> {}
Box<User> $box = new Box(new User());
~~~

Generic syntax is erased from executable PHP. Generated PHPDoc retains template and applied-type relationships, and compatible ordinary PHPDoc generics participate in analysis.

Type parameters keep their declaration identity through properties, methods, local and loop bindings, closures, arrow functions, and inheritance. Applied receivers substitute those parameters through chained member access. Bounds may depend on earlier parameters, such as `TItem : ShoppingCartItem<TProduct>`, and interface capability bounds use nominal implementation relationships. Instance `$this` carries the current applied generic self type; static scopes do not provide `$this`.

## Typed Arrays

`array<T>` is an ordered list and `array<K, V>` is a map. Bare `array` remains the broad PHP array type.

~~~php
array<string> $names = ['Matthew', 'Mark'];
array<string, int> $scores = ['Matthew' => 100];
~~~

List shape, map keys and values, nested and nullable arrays, foreach contracts, and readonly structural mutation are checked. Known collection operations preserve key/value types: `array_filter()` may lose list shape, while `array_values()` restores it. Typed arrays are invariant in the MVP.

## Strict .ppphp Declarations

Every .ppphp parameter and property requires a native type. Every .ppphp callable requires a native return type except `__construct` and `__destruct`. Explicit `mixed`, `array`, `object`, `callable`, and `iterable` are valid deliberate choices. Equivalent omissions in ordinary .php retain PHP behavior.

.ppphp also rejects eval, variable variables, runtime-dependent include paths, return-by-reference declarations, and dynamic property creation. Static paths composed from literals, `__DIR__`, `__FILE__`, and concatenation remain valid.

Production output from every `.ppphp` file enables `declare(strict_types=1)` at the first legal PHP statement. Existing `strict_types=1` declarations are preserved without duplication. An explicit `strict_types=0` is a compile-time error. Ordinary `.php` files keep their original bytes and declaration behavior.

## Checked Errors

Named functions, methods, constructors, interface methods, and abstract methods may declare checked errors:

~~~php
function loadUser(string $id): User throws UserNotFound, StorageFailure
{
}
~~~

A checked exception that can escape a callable must be caught or declared. Resolved calls contribute their declared error contracts, catches remove matching types and subtypes, and overrides may narrow but not widen inherited contracts. PHP Error descendants remain unchecked. File scope, closures, arrow functions, and destructors cannot declare or leak checked errors.

Ordinary PHP and configured stubs may contribute @throws metadata at interoperability boundaries. An invocation whose target or checked-error contract cannot be resolved produces a P4005 warning because the compiler cannot establish a checked-error guarantee.

## `when` Expressions

`when` produces a value from lazy conditional branches and requires a final `else`. A branch `return expression;` produces the expression result; it is not an enclosing callable return. Every reachable path must produce a value or terminate. Results form a canonical union, with `never` branches omitted, and are checked against the surrounding local, assignment, return, call, or array context.

Each branch has a non-escaping child binding scope. Conditions and branch bodies participate in ordinary binding, strict-type, generic, typed-array, and checked-error analysis. Nested `when` expressions are supported in the same direct value positions. Lowering uses deterministic temporaries and closure-free ordinary PHP while preserving lazy, left-to-right evaluation. See [`when` expressions](when-expressions.md) for the supported and rejected positions.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, or a new object model. See [composite types](composite-types.md), [generics](generics.md), [typed arrays](typed-arrays.md), [typed local bindings](typed-local-bindings.md), [typed loop bindings](typed-loop-bindings.md), [checked errors](checked-errors.md), and [`when` expressions](when-expressions.md) for the active rules.
