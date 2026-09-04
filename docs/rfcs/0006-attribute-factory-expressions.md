# RFC 0006 — Attribute Factory Expressions

```text
Status: Draft
Implementation: Proposed For Stage 15E
```

This RFC proposes a constrained ++PHP expression form that allows a statically
named factory call in an eligible attribute argument while lowering to valid
ordinary PHP metadata.

The motivating example comes from AssegaiPHP's NestJS-inspired module system:

```php
#[Module(
    imports: [
        DatabaseModule::forRoot([
            UserEntity::class,
        ]),
    ],
    exports: [
        DatabaseModule::class,
    ],
)]
final class AppModule
{
}
```

AssegaiPHP currently uses an explicit array configuration as a concession to
native PHP's attribute-expression restrictions. That existing framework design
is a real compatibility and acceptance case, but AssegaiPHP development is not
a prerequisite or dependency of ++PHP's language implementation.

## 1. Motivation

PHP attributes provide concise declarative metadata, but native PHP does not
permit arbitrary function or static-method calls as attribute arguments.
Frameworks that need configured imports or providers must therefore encode an
operation indirectly:

```text
- Configuration arrays
- Class/method strings
- Descriptor objects
- Separate bootstrap configuration
```

Those forms are workable but lose the natural API already exposed by the
configured component:

```php
DatabaseModule::forRoot([UserEntity::class])
```

++PHP can type-check that source expression and lower it into legal metadata
without executing application code during compilation.

## 2. Settled Language Direction

The following principles are settled:

```text
- Attribute Factory Expressions are a ++PHP language feature.
- The compiler feature is framework-neutral.
- AssegaiPHP is the motivating and canonical end-to-end acceptance case.
- ++PHP development does not wait for or depend on AssegaiPHP development.
- The compiler recognizes only constrained statically named factory calls.
- The compiler resolves and type-checks the class, method, and arguments.
- The compiler never executes the factory during compilation.
- Generated output is valid ordinary PHP.
- Generated output requires no ++PHP runtime package.
- The factory remains a runtime concern of the consuming framework/library.
- Native PHP users may retain an explicit descriptor or configuration form.
```

The exact framework-neutral lowering protocol remains the central unresolved
design question for this draft.

## 3. Proposed Source Form

A basic factory expression is:

```php
ClassName::methodName(argument1, argument2)
```

when it appears inside an eligible attribute argument:

```php
#[SomeAttribute(
    value: ServiceFactory::create(
        ServiceConfiguration::class,
        ['option' => true],
    ),
)]
final class Consumer
{
}
```

The factory expression may appear directly or as an element inside an
attribute-safe array:

```php
#[Module(
    imports: [
        DatabaseModule::forRoot([UserEntity::class]),
        CacheModule::register(['driver' => RedisCache::class]),
    ],
)]
final class AppModule
{
}
```

## 4. Proposed Eligibility Rules

The initial grammar should accept only calls whose target is statically known:

```text
attribute-factory-expression
    ::= class-name "::" identifier "(" argument-list? ")"
```

The following are candidates for acceptance:

```php
DatabaseModule::forRoot([...]);
self::factory([...]);
parent::factory([...]);
ImportedModuleAlias::configure([...]);
```

The final RFC must decide whether `self`, `parent`, and `static` are allowed in
attribute metadata and how their runtime identity is preserved.

The following are rejected by the initial direction:

```php
$module::forRoot(...);
$module->forRoot(...);
DatabaseModule::$factory(...);
($factoryClass)::forRoot(...);
```

Dynamic call targets remain ordinary dynamic boundaries and cannot be encoded
as deterministic attribute metadata by this feature.

## 5. Factory Contract Validation

The compiler should require the factory to be:

```text
- Resolvable through project, PHP, stub, platform, or dependency declarations.
- Public.
- Static.
- Statically named.
- Callable with the supplied positional/named arguments.
- Compatible with the semantic contract expected by the attribute position.
```

Normal ++PHP checks apply:

```text
- Argument count
- Argument types
- Named argument validity
- Variadics
- By-reference restrictions
- Generic inference
- Return type
- Visibility
- Static/instance correctness
```

The compiler must not accept a call merely because it can encode the method
name as a string.

## 6. Attribute-Safe Arguments

A factory expression records arguments for later runtime invocation. Those
arguments must themselves be representable safely in generated PHP metadata.

Candidate initial argument forms:

```text
- int, float, string, bool, and null literals
- Array literals composed of accepted attribute-safe values
- Class-name constants
- Class constants
- Enum cases
- Native new expressions already permitted by the target PHP attribute grammar
- Nested attribute-factory expressions if explicitly approved
```

Candidate rejected forms:

```php
DatabaseModule::forRoot($entities);
DatabaseModule::forRoot(loadEntities());
DatabaseModule::forRoot(...$entities);
DatabaseModule::forRoot(fn (): array => loadEntities());
DatabaseModule::forRoot($object->entities);
```

The initial feature should not serialize arbitrary runtime state or executable
closures into metadata.

Open questions:

```text
- Whether named arguments are preserved as names in the deferred invocation.
- Whether nested factory expressions are included initially.
- Whether native new expressions may contain factory expressions in their
  constructor arguments.
- Which constant expressions are portable across mixed PHP/++PHP boundaries.
- Whether argument unpacking is always rejected.
```

## 7. Checked Errors

The compiler cannot wrap an attribute argument in an ordinary source-level
`try`/`catch` at its declaration site.

The recommended initial rule is:

```text
A factory used as an Attribute Factory Expression must not expose checked
errors.
```

PHP's unchecked `Error` hierarchy remains governed by the normal runtime model.

An alternative would encode declared checked errors into the descriptor and
make the consuming framework responsible for them, but that weakens ++PHP's
checked-error guarantee and is not recommended for the first release.

This decision remains to be formally accepted.

## 8. No Compile-Time Execution

The compiler must never call:

```php
DatabaseModule::forRoot(...)
```

during checking or lowering.

Compile-time execution would introduce:

```text
- Autoload side effects
- Environment-dependent builds
- Network, filesystem, or database access
- Nondeterministic output
- Arbitrary application-code execution
- Security and sandbox violations
```

The factory invocation is deferred to the consuming framework or library at
runtime.

## 9. Lowering Requirements

The lowering must convert a source factory call into legal PHP 8.4 attribute
metadata while preserving:

```text
- Factory class
- Factory method
- Positional/named arguments
- Original argument order
- Return/consumer contract identity where needed
- Source-map ownership
```

The compiler must not introduce a mandatory ++PHP runtime helper.

The final RFC must choose one general protocol.

## 10. Lowering Alternatives

### 10.1 Existing Consumer Configuration Shape

For a known compatible consumer, lower directly into the configuration array it
already accepts.

Conceptual example:

```php
#[Module(
    imports: [
        [
            'module' => DatabaseModule::class,
            'factory' => 'forRoot',
            'arguments' => [
                [UserEntity::class],
            ],
        ],
    ],
)]
```

Advantages:

```text
- Can interoperate with an existing framework contract.
- May require no framework change.
- Emits only native attribute-safe values.
```

Disadvantages:

```text
- The compiler needs a framework-neutral way to learn the expected shape.
- Hardcoding one framework is unacceptable.
- Different attributes may require different encodings.
```

### 10.2 Typed Deferred Descriptor

Lower to a native `new` expression whose object stores the deferred call:

```php
#[Module(
    imports: [
        new DeferredFactoryCall(
            class: DatabaseModule::class,
            method: 'forRoot',
            arguments: [[UserEntity::class]],
        ),
    ],
)]
```

Advantages:

```text
- Typed metadata.
- Clear runtime representation.
- Native PHP can author the explicit form.
```

Disadvantages:

```text
- The consuming attribute/framework must understand the descriptor.
- A generic descriptor class must live in some ordinary PHP package.
- Requiring a compiler runtime package would violate the product contract.
```

A descriptor supplied by the consuming framework may be acceptable; a mandatory
++PHP descriptor is not.

### 10.3 Generated Provider Class

Generate an ordinary PHP class whose method invokes the static factory, then
place an instance of that provider in the attribute metadata.

Advantages:

```text
- The eventual factory call remains statically named in generated PHP.
- Arguments and return types remain visible to PHP tooling.
- No dynamic callable string is required.
```

Disadvantages:

```text
- Produces additional generated classes.
- The consuming framework must know the provider protocol.
- Namespace, naming, source-map, and lifecycle rules become more complex.
```

### 10.4 Attribute-Declared Lowering Protocol

Allow an attribute class or constructor parameter to declare how factory
expressions are represented.

Advantages:

```text
- Framework-neutral compiler architecture.
- Consumers can opt in explicitly.
- Existing and future frameworks may choose their own runtime representation.
```

Disadvantages:

```text
- Requires a stable metadata protocol.
- The protocol itself must be discoverable without executing user code.
- A compiler-recognized meta-attribute may create packaging questions.
```

This draft does not select among these alternatives.

## 11. Consumer Opt-In

Arbitrary third-party attributes cannot transparently consume a value form they
do not understand.

The final design therefore needs a static opt-in mechanism establishing:

```text
- Which attribute arguments permit factory expressions.
- Which factory return contract is expected.
- Which legal PHP representation should be emitted.
- Which runtime consumer resolves the deferred call.
```

The compiler must discover that contract from source, stubs, portable dependency
metadata, or another non-executing declaration source.

It must not load or instantiate the attribute class to ask it.

## 12. AssegaiPHP Conformance Case

The Stage 15E implementation must include this source form as a canonical
end-to-end fixture:

```php
#[Module(
    imports: [
        DatabaseModule::forRoot([
            UserEntity::class,
        ]),
    ],
    exports: [
        DatabaseModule::class,
    ],
)]
final class AppModule
{
}
```

Acceptance requires:

```text
- The factory call is type-checked.
- The compiler does not execute it.
- Generated PHP is valid.
- The existing AssegaiPHP configured-module behavior can consume the lowering
  directly or through a documented compatible representation.
- Native PHP retains an explicit supported configuration form.
```

This conformance requirement does not make the AssegaiPHP repository an input to
++PHP compilation or implementation scheduling.

## 13. Generic And Return Types

Generic factories should participate in normal call inference:

```php
RepositoryModule::forEntity(UserEntity::class)
```

The compiler must resolve the resulting static type before deciding whether it
is accepted by the attribute position.

An explicit `mixed` return is not sufficient when the consumer requires a
specific configured-module contract, unless the consumer contract deliberately
accepts `mixed`.

## 14. Evaluation Semantics

At runtime, the deferred factory should observe ordinary PHP argument evaluation
semantics as closely as the metadata representation permits.

Because initial arguments are restricted to attribute-safe static metadata,
there should be no arbitrary runtime side effects during argument evaluation.

The factory itself must execute:

```text
- At most once per resolution event defined by the consumer.
- In the order defined by the consumer's normal metadata-processing contract.
```

Caching and repeated-resolution behavior belong to the consumer framework, not
the ++PHP language, unless the final representation requires a minimum
contract.

## 15. Diagnostics

Likely distinct diagnostics include:

```text
- Attribute Does Not Accept Factory Expressions
- Attribute Factory Target Is Dynamic
- Attribute Factory Method Does Not Exist
- Attribute Factory Method Must Be Public And Static
- Attribute Factory Argument Is Not Metadata-Safe
- Attribute Factory Return Type Does Not Match
- Attribute Factory Declares Checked Errors
- Attribute Factory Lowering Contract Is Unavailable
```

Reuse existing call/member diagnostics where they accurately describe the
failure. Do not add a parallel call-checking system.

## 16. Source Maps And Tooling

Definition navigation should resolve:

```text
- Factory class
- Factory method
- Argument class constants and enum cases
- Consumer attribute declaration
```

Generated metadata must map back to the original factory call and arguments.

Editor tooling should distinguish an Attribute Factory Expression from an
ordinary runtime static call where useful.

## 17. Rejected Directions

### 17.1 Compiler Execution Of User Code

Rejected categorically.

### 17.2 AssegaiPHP-Specific Compiler Syntax

Rejected. `Module`, `DatabaseModule`, and `forRoot` are examples, not compiler
built-ins.

### 17.3 Arbitrary Attribute Expressions

Rejected for the initial feature. Variables, closures, instance calls, dynamic
calls, and arbitrary function calls would not lower deterministically to native
attribute metadata.

### 17.4 Mandatory ++PHP Runtime Descriptor

Rejected. Generated applications should not require a ++PHP runtime package.

## 18. Decisions Required Before Acceptance

RFC 0006 cannot be marked Accepted until these are settled:

```text
1. The consumer opt-in mechanism.
2. The framework-neutral lowering protocol.
3. Whether existing array contracts can describe lowering without compiler
   plugins or hardcoded framework knowledge.
4. Eligible attribute positions.
5. Exact accepted argument grammar.
6. Nested factory-expression support.
7. self/parent/static support.
8. Checked-error prohibition.
9. Generic return-type validation.
10. Descriptor/provider lifecycle expectations, if either representation wins.
11. Native PHP authoring equivalent.
12. Source-map and diagnostic behavior.
13. AssegaiPHP conformance lowering.
```

## 19. Proposed Acceptance Criteria

Once finalized, Stage 15E should prove:

```text
- A static public factory can appear in an eligible attribute argument.
- Dynamic factory targets are rejected.
- Factory calls receive normal compiler-owned call checking.
- Factory return types match the consumer contract.
- Metadata-unsafe arguments are rejected.
- Checked-error behavior follows the accepted contract.
- The compiler never executes application or dependency code.
- Generated PHP passes php -l.
- Generated PHP contains no ++PHP-only factory syntax.
- No mandatory ++PHP runtime dependency is introduced.
- The lowering is framework-neutral.
- The AssegaiPHP Module example works end to end.
- Native PHP retains an explicit equivalent form.
- Source maps and definitions refer to original source.
- Mixed PHP/++PHP and portable dependency metadata remain compatible.
```
