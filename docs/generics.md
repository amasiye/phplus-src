# Erased Generics

> **Status:** Available in the current compiler.

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

Type parameters are scoped to their declaration owner. Their semantic identity includes that owner, so unrelated declarations may both use `T` without becoming interchangeable. Names are case-insensitive for duplicate and shadowing checks within the applicable scope. A generic reference must supply the declaration's exact number of arguments; raw references such as `Box` are rejected in `.ppphp`.

A bound follows a colon:

~~~php
class Repository<T : Entity> {}
~~~

An argument must satisfy its bound. Bounds may be class or interface types, applied generic types, or valid intersections, but not unions or recursive references to the same parameter. A later parameter may depend on an earlier one:

~~~php
class Cart<TProduct, TItem : ShoppingCartItem<TProduct>> {}
~~~

Bound checking substitutes the earlier argument before validating the dependent argument. Capability bounds are nominal: a class satisfies an interface bound by implementing that interface, not merely by exposing similarly named members.

Constructor arguments are checked against the expected applied generic type, including constructors imported from ordinary PHP through PHPDoc. Namespace imports and aliases are resolved before the applied declaration is matched, and the same check applies when construction is nested inside typed arrays.

Applied receiver types retain their arguments through property access, method calls, inheritance, interface and trait lookup, nullsafe calls, and chained expressions. For example, `Box<Person>::getValue(): T` resolves to `Person` when invoked on `Box<Person>`. Inside an instance member of `Box<T>`, `$this` is the applied self type `Box<T>`; it is not available in static members or static anonymous callables.

The compiler performs deterministic structured substitution for project-known declarations and delegates broader flow-sensitive inference and unresolved external library details to the pinned PHPStan backend using Composer-aware source context. Types are not rendered to strings and reparsed during semantic substitution.

## Anonymous Callables And Static Scope

Closures and arrow functions may use every type parameter visible in their enclosing callable. A non-static anonymous callable inside an instance method also inherits the applied `$this` type. A static anonymous callable keeps compile-time visibility of the enclosing type parameters but does not receive `$this`.

A static class method cannot use its class's type parameters because it has no applied instance. It may declare and use its own method-level parameters. Anonymous-callable signatures erase to sound native PHP types while generated PHPDoc retains `T`, `Box<T>`, `list<T>`, and other structured relationships.

## Invariance

Generic applications are invariant in the MVP:

~~~php
Box<Dog> $dogs = new Box(new Dog());
Box<Animal> $animals = $dogs; // P3016
~~~

This remains invalid even when `Dog` extends `Animal`. Variance, generic defaults, explicit call-site type arguments, specialization, and hierarchy-aware collection widening are post-MVP.

Wildcard and existential syntax is not part of the MVP. `mixed` is an ordinary concrete generic argument, not shorthand for any specialization. Use a nominal capability interface when a consumer accepts multiple implementations that provide the same contract.

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

Ordinary PHP and configured stubs may define generics using PHPDoc. Their templates and applied types participate in ++PHP analysis, including callback signatures and constructor promotion. Native ++PHP syntax is authoritative; conflicting PHPDoc receives `P3010`.

## Diagnostics

`P3001` remains reserved for compatibility and is no longer emitted for valid generic syntax. Active generic diagnostics use `P3002`–`P3011`, typed-array and invariance diagnostics use `P3012`–`P3016`, and `P3099` is the generic analysis fallback.

See [composite types](composite-types.md) and [natively typed arrays](typed-arrays.md) for the shared type model.

## Generic `when` Results

Branch results retain structured applied generic types. Equal applications collapse; incompatible invariant applications remain incompatible, and `never` branches do not contribute a member. The complete result is checked against generic local, return, parameter, and collection contexts. Generic declarations and references inside a branch are normalized and emitted with their existing `@template`, `@param`, and `@return` relationships when the outer `when` is lowered.
