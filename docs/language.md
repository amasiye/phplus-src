# ++PHP Language Overview

> **Status:** The frontend tokenizes and parses the MVP extension syntax with exact source spans. The extensions remain inactive until their semantic stages.

++PHP is a PHP-shaped source language that adds compile-time validation and erasable language features while preserving PHP runtime behavior. `.ppp` files retain the normal `<?php` opening tag and compile to ordinary `.php` files. Ordinary `.php` files may coexist in the same project and are never rewritten by default.

The MVP language surface is:

- explicitly typed mutable local declarations and `readonly` local bindings;
- strict project-wide typing for ++PHP-authored code;
- erased generics for declarations and type references;
- natively written typed arrays using `array<T>` and `array<K, V>`;
- value-producing `when` expressions;
- checked errors expressed with `throws`; and
- mixed PHP and ++PHP interoperability.

++PHP does not use `val` or `var` local-declaration keywords. A typed declaration creates mutable storage, while `readonly Type $name = value` creates a readonly local binding. Every local declaration has an explicit type and initializer; bare assignment never declares a variable, and there is no inferred declaration form. Typed arrays distinguish `array<T>` lists, `array<K, V>` maps, and explicitly broad bare `array`.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, or a new object model. Where ++PHP adds no explicit compile-time rule or source transformation, PHP behavior remains authoritative.

The current frontend recognizes typed locals, local `readonly`, generic declarations and references, typed arrays, `throws`, and `when`. It records exact extension nodes, masks extension-only syntax in memory, parses the normalized source as PHP 8.4, and maps diagnostics to the original `.ppp` source. Recognized extensions deliberately produce inactive-stage errors: typed locals activate in Stage 5, checked errors in Stage 7, generics and typed arrays in Stage 8, and `when` in Stage 9. No production lowering is performed yet.

Ordinary PHP-only `.ppp` files still build byte-identically. See the [++PHP MVP end-to-end plan](ppphp-mvp-end-to-end-plan.md) for the language contract and implementation sequence.
