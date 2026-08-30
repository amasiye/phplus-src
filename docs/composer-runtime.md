# Composer Runtime Integration

> **Status:** Implemented in Stage 8.

++PHP follows a compiled-project deployment model:

- the compiler analyzes project-owned `.php` and `.ppphp` source, configured stubs, and dependency metadata;
- the configured output tree is the PHP runtime surface;
- Composer remains the dependency and class autoloader; and
- root-project application mappings load generated PHP beneath the configured output.

Like a TypeScript compiler preserving package imports while changing source locations, ++PHP owns the relocation needed by its emitted files. A `.ppphp` entry script may use the project-oriented Composer bootstrap:

~~~php
<?php

require_once __DIR__ . '/vendor/autoload.php';
~~~

During production lowering, the compiler resolves Composer's actual `vendor-dir` from metadata and rewrites that static expression relative to the emitted file. For example, an entry emitted at `build/src/index.php` uses `__DIR__ . '/../../vendor/autoload.php'`. Source code never names `build/`, and no separate source-tree launcher is required.

Other static source-relative includes keep ordinary PHP behavior. When `src/bootstrap.php` is copied to `build/src/bootstrap.php`, `__DIR__ . '/bootstrap.php'` remains unchanged. Ordinary `.php` files remain byte-for-byte copies and are never rewritten.

Dependencies are not duplicated into every output directory. A deployment contains the generated PHP tree together with the project's Composer metadata and installed vendor directory, just as a transpiled application is deployed with its package metadata and dependencies.

## Configure A Project

Preview the change:

~~~bash
ppphp composer:configure --dry-run
~~~

Apply it:

~~~bash
ppphp composer:configure
composer update --lock
composer dump-autoload
~~~

If a new project does not have a `composer.lock` yet, run `composer update` once to create it; `composer update --lock` refreshes an existing lock file.

The command supports the standard `--working-directory`, `--config`, `--format=console|json`, and `--debug` options. It reads `composer.json` as data and never executes Composer, project PHP, scripts, plugins, autoload files, or `vendor/autoload.php`.

The projection:

- rewrites root PSR-4, classmap, and files paths beneath configured source roots to their corresponding build paths;
- handles `autoload` and `autoload-dev`, multiple source roots, PSR-4 string and list forms, nested mappings, and custom output paths;
- preserves unrelated root mappings and dependency configuration; and
- is deterministic and idempotent.

Original source mappings are retained under:

~~~text
extra.ppphp.source-autoload
extra.ppphp.source-autoload-dev
~~~

The compiler uses those preserved mappings for analysis after Composer's runtime mappings point to generated output.

## Build Workflow

Run a complete pathless `ppphp build` before production autoload optimization. It atomically replaces the compiler-owned output tree and records the relocated result in `.ppphp/manifest.json`; Composer metadata and `vendor/` remain outside that tree. Ordinary PSR-4 mappings do not need regeneration after every source edit. Classmap and files mappings may need `composer dump-autoload` when their file set changes; regenerate optimized or authoritative classmaps after the production build.

`ppphp build` reports `P6008` when a relevant root mapping still points at source. This warning does not block projects that intentionally load output manually. Projects without Composer metadata do not receive it.

`P6009`–`P6011` report unsafe projection, write, or mapping-conflict failures. Configuration writes use an atomic replacement and preserve the existing file permissions.
