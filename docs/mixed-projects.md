# Mixed PHP and PHPlus Projects

> **Status:** Planned for MVP Stages 3 and 11. Project discovery and compilation are not implemented.

A PHPlus project will be able to contain both `.php` and `.phplus` files under shared Composer namespaces.

- Ordinary `.php` files will remain unchanged and directly executable.
- `.php` files will contribute native and PHPDoc type information to analysis.
- `.phplus` files will receive the full PHPlus language contract.
- Generated PHP will preserve namespace and relative source paths under the configured output directory.
- Configured stubs will describe boundaries that ordinary PHP signatures and PHPDoc cannot express.

The compiler will discover project-owned sources through project configuration and Composer metadata without treating all of `vendor/` as project source. It will diagnose output collisions and unsafe path overlap, and it will never rewrite ordinary PHP by default.

The [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) is authoritative for mixed-project behavior and staging.
