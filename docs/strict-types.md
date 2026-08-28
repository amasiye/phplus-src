# Strict Project-Wide Types

> **Status:** Planned for MVP Stage 6. ++PHP strict-type checking is not implemented.

The planned `.ppp` contract requires explicit parameter, property, return, and ordinary local types; explicit nullability; initialized local declarations; all-path returns; and no accidental implicit `mixed` in ++PHP-authored declarations. A typed local is mutable unless declared `readonly`; ++PHP does not use inferred `val` or `var` declarations. Bare assignment never declares a variable. Project-wide analysis will check argument, return, assignment, member, and local-variable behavior across relevant source files.

Generated PHP will contain `declare(strict_types=1)`, but PHP's caller-controlled directive is not the whole guarantee. ++PHP will enforce its additional compile-time contract before emission and use PHPStan only as a replaceable analysis backend.

The MVP plans to reject especially dynamic or unsafe constructs in `.ppp`, including `eval`, variable variables, dynamic include paths, assignment by reference, `foreach` by reference, returns by reference, and dynamic property creation. This does not redefine ordinary PHP files or PHP runtime semantics.

See the [++PHP MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative rule set and stage boundaries.
