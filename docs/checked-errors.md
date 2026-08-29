# Checked Errors

> **Status:** `throws` syntax is parsed in Stage 4. Checked-error semantics and emission begin in Stage 7.

++PHP recognizes a `throws` clause on named functions, methods, constructors, interface methods, and abstract methods:

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

Stage 4 records the keyword, each error type, separators, and complete clause span, then masks the clause for normalized PHP parsing. It emits `P4001` because no checked-error behavior is active yet.

In Stage 7, a known checked error that can escape a callable must be caught or declared. Direct throws and called error sets contribute errors; matching catches remove handled types. Overrides may narrow an inherited error set but may not widen it.

At runtime these values remain ordinary PHP exceptions. The `throws` clause will be erased into useful PHPDoc metadata, and PHP's `Error` hierarchy will remain unchecked. Dynamic boundaries that cannot be resolved must produce an explicit warning rather than a false guarantee.

The exact rules and implementation stages are defined by the [++PHP MVP end-to-end plan](ppphp-mvp-end-to-end-plan.md).
