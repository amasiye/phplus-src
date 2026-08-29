# Erased Generics

> **Status:** Generic and typed-array syntax is parsed in Stage 4. Arity, bounds, substitution, inheritance, invariance, checking, erasure, and PHPDoc emission begin in Stage 8.

The frontend recognizes compile-time generic parameters for classes, interfaces, traits, functions, and methods:

```php
class Box<T> {}

function identity<T>(T $value): T
{
    return $value;
}
```

Stage 4 records exact generic declarations, bounds, references, nested arguments, and `array<T>`/`array<K, V>` forms, then masks them only for the normalized PHP parse. It emits `P3001` because these forms are not active language features yet.

In Stage 8, generic relationships will be checked by ++PHP and erased from executable PHP syntax. Generated PHP will preserve compatible relationships through deterministic `@template`, `@param`, `@return`, `@extends`, `@implements`, and related PHPDoc tags.

The same erased type system will support `array<T>` for lists and `array<K, V>` for maps while retaining broad native `array` where explicitly written.

The MVP will not provide runtime reification, specialization, monomorphization, variance, defaults, higher-kinded types, or explicit call-site type arguments. Operations that require a type parameter at runtime, such as `new T()` or `T::class`, will be rejected.

See the [++PHP MVP end-to-end plan](ppphp-mvp-end-to-end-plan.md) for the authoritative supported forms and constraints.
