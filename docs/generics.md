# Erased Generics

> **Status:** Generic and typed-array syntax is parsed but inactive. Arity, bounds, substitution, inheritance, checking, erasure, and PHPDoc emission begin in Stage 8.

The frontend recognizes compile-time generic parameters for classes, interfaces, traits, functions, and methods:

~~~php
class Box<T> {}

function identity<T>(T $value): T
{
    return $value;
}
~~~

It also recognizes generic references and array<T> or array<K, V> types in approved type positions. These forms retain exact source nodes and report P3001. Stage 5 does not activate a typed local whose SourceType contains a generic or typed-array reference.

In Stage 8, generic relationships will be checked and erased from executable PHP syntax. Generated PHP will preserve compatible information through deterministic template, parameter, return, extends, implements, and array PHPDoc tags.

The MVP will not provide runtime reification, specialization, monomorphization, variance, defaults, higher-kinded types, or explicit call-site type arguments. Operations that require a type parameter at runtime, such as new T() or T::class, will be rejected.
