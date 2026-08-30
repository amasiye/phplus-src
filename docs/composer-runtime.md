# Composer Runtime Integration

> **Status:** Implemented in Stage 8.

++PHP keeps source analysis and runtime loading separate:

- the compiler analyzes project-owned `.php` and `.ppphp` source, configured stubs, and dependency metadata;
- Composer remains the dependency and class autoloader; and
- root-project application mappings load generated PHP beneath the configured build output.

Dependencies and `vendor/` are not copied into the build tree. Runtime launchers therefore remain at a stable project-root, `public/`, or `bin/` location and load the project-root Composer installation:

~~~php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';
require $projectRoot . '/build/ppphp/application.php';
~~~

The output is not a standalone relocatable package.

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

Run a complete `ppphp build` before production autoload optimization. Ordinary PSR-4 mappings do not need regeneration after every source edit. Classmap and files mappings may need `composer dump-autoload` when their file set changes; regenerate optimized or authoritative classmaps after the production build.

`ppphp build` reports `P6008` when a relevant root mapping still points at source. This warning does not block projects that intentionally load output manually. Projects without Composer metadata do not receive it.

`P6009`–`P6011` report unsafe projection, write, or mapping-conflict failures. Configuration writes use an atomic replacement and preserve the existing file permissions.
