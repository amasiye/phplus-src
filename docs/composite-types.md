# Composite Types

> **Status:** Implemented in Stage 8.

++PHP uses one structured semantic model for native, composite, generic, and typed-array types. Frontend nodes retain the original spelling; semantic comparison uses a deterministic canonical form.

## Supported Forms

Composite types are available in local and loop declarations, parameters, returns, properties, generic arguments, and nested typed arrays:

~~~php
int|string $identifier = 1;
int|string|null $result = null;
Countable&Iterator $records = new RecordSet();
(Countable&Iterator)|array $source = [];
~~~

`?T` is canonicalized as `T|null`. Union and intersection member order does not affect equality, so `string|int` and `int|string` describe the same type. Rendering remains deterministic.

## Validation

The compiler validates composite shapes before lowering. It rejects duplicate or redundant members, `mixed` combined with another member, scalar intersections, `void` or `never` in non-return positions, nullable shorthand combined with a union, unparenthesized intersection members in a union, and unions nested inside intersections.

Use parentheses for disjunctive normal form:

~~~php
(Countable&Iterator)|array $source = [];
~~~

For a union target, every possible source type must be accepted by at least one target member. An intersection target requires every member. Known project hierarchy information proves class and interface relationships; unresolved relationships are left to the analysis backend rather than guessed.

## Lowering

PHP-native parameter, property, and return composites remain native when they contain no erased syntax. Local and loop syntax is erased and preserved as PHPDoc:

~~~php
int|string $identifier = 1;
~~~

becomes:

~~~php
/** @var int|string $identifier */ $identifier = 1;
~~~

When a composite contains a generic or type parameter, generated PHP uses the widest sound legal native type and retains the complete type in PHPDoc.

Compiler-owned composite diagnostics use `P2030`–`P2032`.
