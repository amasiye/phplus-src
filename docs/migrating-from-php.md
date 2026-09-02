# Migrating From PHP

++PHP is designed for incremental adoption. Keep existing `.php` files and migrate one owned source at a time.

1. Install the compiler as a development dependency with the exact RC constraint.
2. Run `vendor/bin/ppphp init`.
3. Configure source roots and compiler-owned output, cache, and stub paths in `ppphp.json`.
4. Run `ppphp composer:configure` when root Composer mappings target source.
5. Keep existing `.php` files; the compiler analyzes them and copies selected files to the build tree.
6. Rename one selected source to `.ppphp`.
7. Add explicit parameter, property, return, and local types.
8. Replace declaration-by-assignment with explicit typed local declarations.
9. Make nullability explicit.
10. Add or import checked-error contracts where the boundary requires them.
11. Run a complete `ppphp build`.
12. Run and deploy from `build/ppphp`, not from `.ppphp` source.

## Common source changes

```php
string $name = trim($input);
readonly int $limit = 10;
array<string> $names = ['Matthew', 'Mark'];
array<string, int> $scores = ['Matthew' => 100];
```

Erased generics retain precise compile-time contracts:

```php
class Box<T>
{
    public function __construct(public T $value) {}
}
```

Checked errors are caught or declared:

```php
function loadUser(string $id): User throws UserNotFound
{
    throw new UserNotFound($id);
}
```

`when` is a value expression with a mandatory final `else`:

```php
string $label = when ($score >= 50) {
    return 'Pass';
} else {
    return 'Retry';
};
```

Ordinary PHP remains ordinary PHP. It does not receive ++PHP declaration-completeness rules, and its source bytes are preserved in the generated mixed tree. See [Mixed Projects](mixed-projects.md), [Strict Types](strict-types.md), and [Getting Started](getting-started.md).
