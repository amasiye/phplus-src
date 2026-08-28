# PHPlus Language Overview

> **Status:** The frontend currently accepts ordinary PHP 8.4 syntax in mixed `.php` and `.phplus` projects. The language extensions below are planned.

PHPlus is a PHP-shaped source language that adds compile-time validation and erasable language features while preserving PHP runtime behavior. `.phplus` files retain the normal `<?php` opening tag and compile to ordinary `.php` files. Ordinary `.php` files may coexist in the same project and are never rewritten by default.

The planned MVP capabilities are:

- explicitly typed mutable local declarations and `readonly` local bindings;
- strict project-wide typing for PHPlus-authored code;
- erased generics for declarations and type references;
- natively written typed arrays using `array<T>` and `array<K, V>`;
- value-producing `when` expressions;
- checked errors expressed with `throws`; and
- mixed PHP and PHPlus interoperability.

PHPlus does not use `val` or `var` local-declaration keywords. A typed declaration creates mutable storage, while `readonly Type $name = value` creates a readonly local binding. Every local declaration has an explicit type and initializer; bare assignment never declares a variable, and there is no inferred declaration form. Typed arrays distinguish `array<T>` lists, `array<K, V>` maps, and explicitly broad bare `array`.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, or a new object model. Where PHPlus adds no explicit compile-time rule or source transformation, PHP behavior remains authoritative.

The current implementation discovers complete mixed source sets, reports ordinary PHP syntax diagnostics against original files, validates configured stubs, and builds selected `.phplus` files to byte-identical `.php` files. It does not recognize planned PHPlus-specific syntax yet. See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the language contract and implementation sequence.
