# Strict Project-Wide Types

> **Status:** Planned for MVP Stage 6. PHPlus strict-type checking is not implemented.

The planned `.phplus` contract requires explicit parameter, property, and return types; explicit nullability; initialized local bindings; all-path returns; and no accidental implicit `mixed` in PHPlus-authored declarations. Project-wide analysis will check argument, return, assignment, member, and local-variable behavior across relevant source files.

Generated PHP will contain `declare(strict_types=1)`, but PHP's caller-controlled directive is not the whole guarantee. PHPlus will enforce its additional compile-time contract before emission and use PHPStan only as a replaceable analysis backend.

The MVP plans to reject especially dynamic or unsafe constructs in `.phplus`, including `eval`, variable variables, dynamic include paths, assignment by reference, `foreach` by reference, returns by reference, and dynamic property creation. This does not redefine ordinary PHP files or PHP runtime semantics.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative rule set and stage boundaries.
