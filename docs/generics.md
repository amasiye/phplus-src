# Erased Generics

> **Status:** Implemented in Stage 8.

++PHP supports compile-time generic parameters on classes, interfaces, traits, functions, and methods:

~~~php
interface Entity {}

class Box<T : Entity>
{
    public function read(): T
    {
    }
}

function identity<T>(T $value): T
{
    return $value;
}
~~~

Generic references may appear in approved type positions and may nest:

~~~php
Box<User> $box = new Box(new User());
array<string, Box<User>> $boxes = [];
~~~

## Scope, Arity, And Bounds

Type parameters are scoped to their declaration owner. Names are case-insensitive for duplicate and shadowing checks. A generic reference must supply the declaration's exact number of arguments; raw references such as `Box` are rejected in `.ppphp`.

A bound follows a colon:

~~~php
class Repository<T : Entity> {}
~~~

An argument must satisfy its bound. Bounds may be class or interface types, including valid intersections, but not unions or recursive references to the same parameter.

Constructor arguments are checked against the expected applied generic type, including constructors imported from ordinary PHP through PHPDoc. The compiler performs deterministic local substitution and delegates broader generic function and method inference, plus unresolved external class or interface bounds, to the pinned PHPStan backend using Composer-aware source context.

## Invariance

Generic applications are invariant in the MVP:

~~~php
Box<Dog> $dogs = new Box(new Dog());
Box<Animal> $animals = $dogs; // P3016
~~~

This remains invalid even when `Dog` extends `Animal`. Variance, generic defaults, explicit call-site type arguments, specialization, and hierarchy-aware collection widening are post-MVP.

## Erasure

Generic syntax exists only at compile time. It cannot drive `new T()`, `$value instanceof T`, `T::class`, or a static class-member signature. Generated PHP removes generic declaration and application syntax and emits PHPDoc:

~~~php
/** @template T of Entity */
class Box
{
    /** @return T */
    public function read(): Entity
    {
    }
}
~~~

The compiler preserves templates, parameters, returns, properties, `@extends`, `@implements`, `@use`, and checked-error `@throws` metadata through one declaration-level emission pass. Existing descriptions, attributes, unrelated tags, and newline style are retained.

Ordinary PHP and configured stubs may define generics using PHPDoc. Their templates and applied types participate in ++PHP analysis. Native ++PHP syntax is authoritative; conflicting PHPDoc receives `P3010`.

## Diagnostics

`P3001` remains reserved for compatibility and is no longer emitted for valid generic syntax. Active generic diagnostics use `P3002`–`P3011`, typed-array and invariance diagnostics use `P3012`–`P3016`, and `P3099` is the generic analysis fallback.

See [composite types](composite-types.md) and [natively typed arrays](typed-arrays.md) for the shared type model.
