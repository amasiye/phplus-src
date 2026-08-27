# PHPlus Language Overview

> **Status:** Planned MVP behavior. No PHPlus language extension is implemented yet.

PHPlus is a PHP-shaped source language that will add compile-time validation and a small set of erasable language features while preserving PHP runtime behavior. `.phplus` files will retain the normal `<?php` opening tag and compile to ordinary `.php` files for the official PHP runtime. Ordinary `.php` files in the same project will remain unchanged.

The planned MVP contains six capabilities:

- erased generics for declarations and type references;
- strict project-wide typing for PHPlus-authored code;
- `val` and `var` local bindings;
- value-producing `when` expressions;
- checked errors expressed with `throws`; and
- mixed PHP and PHPlus projects.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, a new object model, or Doria semantics. Where PHPlus adds no explicit rule, PHP behavior remains authoritative.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative language contract and staged implementation sequence.
