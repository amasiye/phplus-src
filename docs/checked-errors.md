# Checked Errors

> **Status:** Implemented in Stage 7.

++PHP adds compile-time error contracts without changing PHP's runtime exception model. A named callable declares the checked exceptions it may let escape:

~~~php
function loadUser(string $id): User
    throws UserNotFound, StorageFailure
{
    if ($id === '') {
        throw new UserNotFound($id);
    }

    return readStoredUser($id);
}
~~~

The syntax is available on named functions, methods, constructors, interface methods, and abstract methods.

## Error Sets

A callable's escaping error set is computed as:

~~~text
directly thrown checked exceptions
+ checked contracts from resolved calls and constructors
- exceptions handled by matching catches
= checked exceptions that must be declared
~~~

A catch handles its declared type and all subtypes. Multi-catch is supported. Checked exceptions thrown by catch or finally bodies contribute normally; a finally block that cannot complete replaces pending control flow according to ordinary PHP behavior.

A callable may declare a broader checked supertype. Duplicate declarations are rejected. An overriding method may preserve or narrow every inherited contract but may not widen it. Constructors do not inherit a parent constructor's error contract.

## Checked And Unchecked Throwables

Exception descendants are checked. PHP Error descendants are unchecked and do not create catch-or-declare obligations. Throwable itself is a broad checked contract because it includes checked exceptions.

Every declared or documented error type must resolve to a Throwable implementation. Scalar, unknown non-throwable, nullable, generic, and intersection error declarations are rejected.

## Scope Boundaries

Executable file scope, closures, arrow functions, and destructors have no throws clause. A checked exception must be caught before it escapes one of those boundaries.

Dynamic calls, unresolved named callables, variable method names, and similar boundaries whose target or contract cannot be resolved produce warning P4005. The warning is explicit about the missing guarantee and does not block checking or building by itself.

## PHP And Stub Interoperability

Ordinary .php functions and methods may contribute checked contracts through `@throws` tags. Configured `.stub.php` declarations use the same metadata and take precedence over matching project PHP declarations.

A .ppp callable must use a native throws clause. PHPDoc alone is rejected, and PHPDoc that conflicts with the native clause is rejected. Matching descriptions and unrelated tags are preserved during lowering.

## Lowering

The throws clause is erased and its canonical fully qualified types are merged into the callable's PHPDoc:

~~~php
/** @throws \App\UserNotFound|\App\StorageFailure */
function loadUser(string $id): User
{
}
~~~

Existing descriptions, attributes, and unrelated PHPDoc tags remain intact. Matching `@throws` tags are not duplicated. Generated code contains ordinary PHP exceptions and requires no ++PHP runtime support.

## Diagnostics

~~~text
P4002  Error Type Is Not Throwable
P4003  Checked Error Is Not Handled
P4004  Checked Error Declaration Is Not Covariant
P4005  Unchecked Call Boundary
P4006  Native Throws Clause Is Required
P4007  Throws Documentation Conflicts With Native Clause
P4008  Checked Error Cannot Escape File Scope
P4009  Checked Error Cannot Escape Anonymous Callable
P4010  Checked Error Cannot Escape Destructor
P4011  Duplicate Error Declaration
P4012  Caught Error Is Never Thrown
P4013  Error Catch Is Unreachable
~~~

Diagnostics use original source paths and spans. Backend checked-exception findings are mapped to the same stable P4xxx family and deduplicated from compiler-owned findings.
