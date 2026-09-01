# Strict Project-Wide Types

> **Status:** Implemented in Stage 6, completed for structured generic project context after Stage 12, and backed by compiler-owned type flow in Stage 13B.

.ppphp files use a strict declaration contract in addition to the typed-local rules.

## Declaration Requirements

Every parameter requires a native type, including parameters on functions, methods, closures, arrow functions, constructors, promoted properties, and variadics. Every callable requires a native return type except `__construct` and `__destruct`. Every property requires a native type.

~~~php
final class User
{
    public string $name;
    private mixed $metadata;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->metadata = null;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
~~~

PHPDoc alone does not satisfy a .ppphp declaration requirement. Native unions and intersections remain native PHP declarations. Generic references and typed arrays use the widest sound native declaration plus precise generated PHPDoc. Explicit broad types are deliberate and valid:

~~~text
mixed
array
object
callable
iterable
~~~

## Project Analysis

`ppphp check` and `ppphp build` detect argument and return mismatches, missing returns, nullability violations, unknown types/functions/methods/properties, incompatible property assignments, and checked-error contract violations. Selected `.ppphp` and `.php` files are checked together with valid unselected project declaration context, configured stubs, Composer metadata, and PHPDoc. A focused command retains valid signatures from an unselected source even when that source has an unrelated body error; an invalid declaration header is never fabricated as context.

The compiler owns ++PHP declaration completeness, supported expression assignability, known call/member/constructor validation, return and all-path flow, property access and definite initialization, composite and generic validity, owner-qualified type parameters, typed-array and collection contracts, typed-local and readonly rules, unsafe-construct restrictions, source spans, and stable diagnostic codes. The Composer-locked PHPStan process remains mandatory for broad built-in, unindexed dependency, deep ordinary-PHP-body, and optional lint analysis.

## Unsafe Constructs

.ppphp rejects:

- `eval`;
- variable variables;
- include or require targets that depend on runtime values;
- return-by-reference declarations;
- dynamic property creation; and
- the reference forms already rejected by the typed-local binding rules.

Literal paths and paths composed from string fragments, `__DIR__`, `__FILE__`, and concatenation remain valid:

~~~php
require_once __DIR__ . '/bootstrap.php';
include __FILE__ . '.inc';
~~~

The project-oriented Composer bootstrap `__DIR__ . '/vendor/autoload.php'` is resolved from Composer metadata and rebased for the emitted file during production lowering. This is the only compiler-owned runtime-path relocation; ordinary static includes retain PHP's relative-path behavior.

## Ordinary PHP Boundary

.php files retain PHP declaration semantics. They may omit native parameter, return, and property types and contribute native/PHPDoc call, member, generic, array, and checked-error contracts to selected ++PHP. Known cross-language calls are compiler-validated. ++PHP-only declaration-completeness diagnostics are not applied to them, and deep errors inside ordinary-PHP bodies remain supplemental.

## `when` Result Contexts

A `when` result is checked as the real value at its source position, never as the frontend's parser placeholder. Its complete canonical union must be assignable to the declared local, existing assignment target, callable return type, resolved call parameter, or typed-array element type. A branch whose result is unknown remains conservative for PHPStan refinement; a provable mismatch remains a compiler error. Branch conditions and bodies receive the same strict name, member, unsafe-construct, and declaration checks as surrounding `.ppphp` code.

## Diagnostics

Stage 6 uses `P2011`–`P2025` for strict declarations, type relationships, symbol/member failures, dynamic properties, unsafe constructs, and nullability. `P2099` is the stable fallback for a backend finding without a dedicated category. `P6005`–`P6007` report backend execution, result parsing, and workspace infrastructure failures.
