# Strict Project-Wide Types

> **Status:** Stage 5 local declaration and fixed-assignment checks are active. Complete project-wide type analysis remains Stage 6 work.

Every ordinary local in .ppp source is introduced with an explicit type and initializer. Bare assignment cannot declare a variable. A mutable binding may receive later compatible values without widening its declared type; a readonly binding cannot receive a later value or structural mutation.

Stage 5 detects definitive local mismatches for literals, broad arrays, closures, exact new expressions, casts, known local reads, and simple unary and arithmetic expressions. It supports nullability, unions, intersections conservatively, mixed, broad array, callable, and named types. Unresolved calls and class-hierarchy relationships do not produce guessed errors.

Stage 6 will add the whole-project contract for parameters, properties, returns, all-path returns, members, nullability, arguments, PHPDoc imports, and accidental implicit mixed. PHPStan remains a pinned, replaceable analysis backend; it does not define ++PHP semantics.

The MVP plans to reject especially dynamic or unsafe constructs in .ppp, including eval, variable variables, dynamic include paths, returns by reference, and dynamic property creation. Stage 5 already rejects explicit reference creation and foreach by reference under its local-binding rules. Ordinary .php files retain PHP semantics.
