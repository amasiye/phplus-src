# `when` Expressions

> **Status:** Planned for MVP Stage 9. `when` syntax and lowering are not implemented.

The MVP plans a value-producing conditional expression:

```php
val $label = when ($score >= 80) {
    return "Excellent";
} else when ($score >= 50) {
    return "Pass";
} else {
    return "Fail";
};
```

A final `else` will be mandatory, and every reachable branch must yield a value or terminate. Conditions will evaluate from left to right and at most once. Branch-local bindings will not escape, a throwing branch will have type `never`, and `break` or `continue` will be invalid inside value-producing branches.

Lowering will use deterministic ordinary PHP control flow and collision-free temporary variables. It will not use synthetic closures or change PHP evaluation order.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for authoritative supported positions and lowering requirements.
