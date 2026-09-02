# RFC 0001 — Immutable Records

```text
Status: Accepted
Implementation: Scheduled For Stage 15A
```

This RFC settles the source and lowering contract for the first post-MVP
language feature. It does not implement `record` syntax.

## Source Form

```php
<?php

namespace App\DTO;

record UserProfile(
    public string $username,
    public array<string> $roles,
) {
    public string $displayName {
        get {
            return '@' . $this->username;
        }
    }

    public function withUpgradedAccess(string $accessLevel): self
    {
        return when ($accessLevel === 'admin') {
            string $audit = "Upgrading {$this->username}";
            error_log($audit);

            return new self(
                username: $this->username,
                roles: [...$this->roles, 'admin'],
            );
        } else {
            return $this;
        };
    }
}
```

`record` is a contextual class-like declaration keyword. Records are
implicitly final. Signature components require explicit types and become
public readonly promoted properties; the compiler generates the canonical
constructor. Records may be generic, implement interfaces, and contain
instance methods, static methods, class constants, and virtual get-only computed properties.

The initial feature rejects set hooks, backed computed-property hooks,
additional backed instance state, static properties, custom constructors,
and attempts to extend a class, as well as variadic components and components
passed by reference.
Writing `readonly` again on a component is redundant and rejected. State
evolution uses methods that return a new record instance; returning `$this`
when no state changes is valid.

## Lowering

Records lower to a final class with public readonly promoted components and a
compiler-generated constructor. They do not lower to a `readonly class`,
because PHP 8.4 readonly properties and property hooks cannot together express
the required virtual computed-property model.

```php
<?php

declare(strict_types=1);

namespace App\DTO;

final class UserProfile
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly string $username,
        public readonly array $roles,
    ) {
    }

    public string $displayName {
        get {
            return '@' . $this->username;
        }
    }
}
```

Generated PHP requires no ++PHP runtime.

## Immutability And Identity

Records provide shallow PHP-compatible immutability. Components cannot be
reassigned, and arrays cannot be structurally mutated through their readonly
properties. Referenced objects retain their own mutability rules.

Records preserve ordinary PHP object identity; they do not introduce
copy-by-value behavior. Equal components do not imply identity or synthesized equality.
The initial feature does not synthesize value equality, hashing,
`copy()`, with expressions, destructuring, serialization, `toArray()`,
`jsonSerialize()`, or pattern matching. Ordinary methods may implement
application-specific behavior.
