# Mixed PHP and PHPlus Projects

> **Status:** Project discovery and mixed-source syntax checking are implemented. Cross-file semantic analysis is planned for later MVP stages.

A PHPlus project may contain both `.php` and `.phplus` files beneath one or more configured source roots.

- Ordinary `.php` files remain unchanged and directly executable.
- `.php` files participate in project checking and will later contribute native and PHPDoc type information to analysis.
- `.phplus` files are checked and may be emitted to source-root-relative `.php` paths beneath the configured output directory.
- Configured `.stub.php` files are global checking context and will describe boundaries that ordinary PHP signatures and PHPDoc cannot express.
- Composer PSR-4, classmap, files, custom vendor paths, and installed-package metadata are recorded as interoperability context without treating dependencies as project-owned source.

`phplus check` selects the complete project by default and accepts a project-owned file or source subtree for focused work. `phplus build` validates the complete project and emits every `.phplus` file by default; focused builds use the same file or subtree boundary. There is no configured entry point and no dependency-based tree-shaking.

Discovery applies exclusions, avoids directory-symlink traversal, deduplicates physical files, and assigns overlapping roots deterministically. Output collisions are diagnosed before emission. Ordinary PHP is never rewritten by default.

The [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) is authoritative for later semantic interoperability behavior.
