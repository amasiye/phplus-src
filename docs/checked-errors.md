# Checked Errors

> **Status:** Planned for MVP Stage 7. Checked errors are not implemented.

PHPlus plans to let a callable declare checked errors with a `throws` clause:

```php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
}
```

A known checked error that can escape a callable must be caught or declared. Direct throws and called error sets contribute errors; matching catches remove handled types. Overrides may narrow an inherited error set but may not widen it.

At runtime these values remain ordinary PHP exceptions. The `throws` clause will be erased into useful PHPDoc metadata, and PHP's `Error` hierarchy will remain unchecked. Dynamic boundaries that cannot be resolved must produce an explicit warning rather than a false guarantee.

The exact rules and implementation stages are defined by the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md).
