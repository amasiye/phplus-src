# when Expressions

> **Status:** when syntax is parsed but inactive. Result typing and production lowering begin in Stage 9.

The frontend recognizes the value-producing conditional expression:

~~~php
string $label = when ($score >= 80) {
    return 'Excellent';
} else when ($score >= 50) {
    return 'Pass';
} else {
    return 'Fail';
};
~~~

It requires a final else, records every keyword, condition, and braced body, and substitutes an in-memory null placeholder only for normalized PHP parsing. It reports P5001; the placeholder is never emitted.

Stage 5 typed local declarations are active, but a declaration whose initializer contains when remains build-blocked by P5001.

In Stage 9, every reachable branch must yield a value or terminate. Conditions will evaluate from left to right and at most once. Branch-local bindings will not escape, a throwing branch will have type never, and break or continue will be invalid inside value-producing branches.

Lowering will use deterministic ordinary PHP control flow and collision-free temporary variables without synthetic closures or changed evaluation order.
