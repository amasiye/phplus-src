# Checked Errors

> **Status:** throws syntax is parsed but inactive. Checked-error semantics and emission begin in Stage 7.

++PHP recognizes a throws clause on named functions, methods, constructors, interface methods, and abstract methods:

~~~php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
~~~

The frontend records the keyword, each error type, separators, and complete clause span, then masks the clause only for normalized PHP parsing. It reports P4001; no checked-error behavior or production erasure is active yet.

Stage 5 typed local declarations may appear elsewhere in the same source, but any throws clause still blocks checking and building.

In Stage 7, a known checked error that can escape a callable must be caught or declared. Direct throws and called error sets contribute errors; matching catches remove handled types. Overrides may narrow an inherited error set but may not widen it.

At runtime these values remain ordinary PHP exceptions. The clause will be erased into PHPDoc metadata, and PHP's Error hierarchy will remain unchecked. Dynamic boundaries that cannot be resolved must produce an explicit warning rather than a false guarantee.
