# Erased Generics

> **Status:** Planned for MVP Stage 8. Generic syntax and checking are not implemented.

The MVP plans compile-time generic parameters for classes, interfaces, traits, functions, and methods:

```php
class Box<T> {}

function identity<T>(T $value): T
{
    return $value;
}
```

Generic relationships will be checked by PHPlus and erased from executable PHP syntax. Generated PHP will preserve compatible relationships through deterministic `@template`, `@param`, `@return`, `@extends`, `@implements`, and related PHPDoc tags.

The same erased type system will support `array<T>` for lists and `array<K, V>` for maps while retaining broad native `array` where explicitly written.

The MVP will not provide runtime reification, specialization, monomorphization, variance, defaults, higher-kinded types, or explicit call-site type arguments. Operations that require a type parameter at runtime, such as `new T()` or `T::class`, will be rejected.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative supported forms and constraints.
