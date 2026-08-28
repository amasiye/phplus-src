# PHPlus Language Overview

> **Status:** The frontend currently accepts ordinary PHP 8.4 syntax in `.phplus` files. The language extensions below are planned.

PHPlus is a PHP-shaped source language that adds compile-time validation and erasable language features while preserving PHP runtime behavior. `.phplus` files retain the normal `<?php` opening tag and will compile to ordinary `.php` files. Ordinary `.php` files may coexist in the same project.

The planned MVP capabilities are:

- erased generics for declarations and type references;
- strict project-wide typing for PHPlus-authored code;
- `val` and `var` local bindings;
- value-producing `when` expressions;
- checked errors expressed with `throws`; and
- mixed PHP and PHPlus projects.

The MVP does not introduce a custom runtime, native compilation, reified generics, macros, async/await, or a new object model. Where PHPlus adds no explicit compile-time rule or source transformation, PHP behavior remains authoritative.

The current implementation parses one explicit `.phplus` file, reports syntax diagnostics against the original source, and can build a byte-identical `.php` file. It does not recognize or execute any planned PHPlus-specific syntax. See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the language contract and implementation sequence.
