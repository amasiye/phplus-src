# Strict Project-Wide Types

> **Status:** Implemented in Stage 6.

.ppp files use a strict declaration contract in addition to the typed-local rules.

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

PHPDoc alone does not satisfy a .ppp declaration requirement. Explicit broad types are deliberate and valid:

~~~text
mixed
array
object
callable
iterable
~~~

## Project Analysis

`ppphp check` and `ppphp build` detect argument and return mismatches, missing returns, nullability violations, unknown types/functions/methods/properties, incompatible property assignments, and checked-error contract violations. Selected `.ppp` and `.php` files are checked together with valid unselected project context, configured stubs, Composer metadata, and PHPDoc.

The compiler owns ++PHP declaration completeness, typed-local and readonly rules, unsafe-construct restrictions, source spans, and stable diagnostic codes. The Composer-locked PHPStan process supplies flow-sensitive PHP analysis behind a replaceable compiler interface.

## Unsafe Constructs

.ppp rejects:

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

## Ordinary PHP Boundary

.php files retain PHP declaration semantics. They may omit native parameter, return, and property types, contribute native and PHPDoc signatures, and receive genuine type and symbol diagnostics when selected. ++PHP-only declaration-completeness diagnostics are not applied to them.

## Diagnostics

Stage 6 uses `P2011`–`P2025` for strict declarations, type relationships, symbol/member failures, dynamic properties, unsafe constructs, and nullability. `P2099` is the stable fallback for a backend finding without a dedicated category. `P6005`–`P6007` report backend execution, result parsing, and workspace infrastructure failures.
