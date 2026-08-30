# Typed Loop Bindings

> **Status:** Implemented in Stage 7.

++PHP permits explicit local declarations in `for` and `foreach` headers. These declarations use the same fixed-type, readonly, duplicate-name, and declaration-before-use rules as ordinary typed locals.

## For Initializers

A `for` initializer may declare one typed binding:

~~~php
for (int $index = 0; $index < 10; ++$index) {
    echo $index;
}
~~~

The binding enters the enclosing PHP-compatible variable scope and remains available after the loop. Its initializer and later writes must be assignable to the declared type. A readonly declaration is permitted, but any loop update that writes it is rejected by the readonly-local rules.

Multiple typed declarations in one `for` initializer are not supported in the MVP. Declare additional values before the loop.

## Foreach Bindings

A `foreach` header may declare its value, or both key and value:

~~~php
function printValues(array $values): void
{
    foreach ($values as mixed $value) {
        echo $value;
    }
}

function printEntries(array $values): void
{
    foreach ($values as mixed $key => mixed $value) {
        echo $key, '=', $value;
    }
}
~~~

A broad native `array` supplies `mixed` keys and values, so new typed bindings over that source must use the exact canonical `mixed` contract. Element-aware `array<T>` and `array<K, V>` validation remains inactive until Stage 8.

A bare `foreach` target is not a declaration and must already name a mutable binding. Foreach-by-reference and readonly foreach declarations are unsupported.

## Scope And Initialization

Loop declarations share their enclosing file or callable scope. Redeclaring the same name in another loop or ordinary typed declaration is a duplicate declaration.

A `for` initializer executes before its condition, so its binding is initialized after the statement. A `foreach` body may execute zero times, so a newly declared foreach binding may be read afterwards only when control flow proves initialization, such as through `isset`.

## Lowering

The type syntax is erased and emitted as PHPDoc immediately before ordinary PHP loop syntax:

~~~php
/** @var int $index */
for ($index = 0; $index < 10; ++$index) {
}

/**
 * @var mixed $key
 * @var mixed $value
 */
foreach ($values as $key => $value) {
}
~~~

The loop body, expressions, comments, newline style, and unaffected source bytes are preserved.

## Diagnostics

~~~text
P2026  Loop Binding Type Does Not Match
P2027  Local Variable May Be Uninitialized
P2028  Readonly Foreach Binding Is Not Supported
P2029  Multiple Typed For Initializers Are Not Supported
~~~

General binding diagnostics such as P2004 for duplicate declarations and P2005 for readonly reassignment also apply.
