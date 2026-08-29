# `when` Expressions

> **Status:** `when` syntax is parsed in Stage 4. Result typing and production lowering begin in Stage 9.

The frontend recognizes the value-producing conditional expression:

```php
string $label = when ($score >= 80) {
    return "Excellent";
} else when ($score >= 50) {
    return "Pass";
} else {
    return "Fail";
};
```

Stage 4 requires a final `else`, records every keyword, condition, and braced body, and substitutes an in-memory `null` placeholder for the normalized PHP parse. It emits `P5001`; the placeholder is never emitted as runtime behavior.

In Stage 9, every reachable branch must yield a value or terminate. Conditions will evaluate from left to right and at most once. Branch-local bindings will not escape, a throwing branch will have type `never`, and `break` or `continue` will be invalid inside value-producing branches.

Lowering will use deterministic ordinary PHP control flow and collision-free temporary variables. It will not use synthetic closures or change PHP evaluation order.

See the [++PHP MVP end-to-end plan](ppphp-mvp-end-to-end-plan.md) for authoritative supported positions and lowering requirements.
