# PHP And ++PHP Interoperability

> **Status:** The Stage 11 mixed-project contract is implemented; Stage 13C makes ordinary-PHP, configured-stub, installed-dependency, and target-PHP declaration boundaries compiler owned.

++PHP is designed for incremental adoption. Project-owned `.php` and `.ppphp` files may share namespaces, call each other, implement each other's interfaces, and exchange native and PHPDoc types. The compiler analyzes the source project and emits one ordinary-PHP runtime tree: `.ppphp` files are compiled and `.php` files are copied byte-for-byte.

## Canonical Application

[`examples/mixed-application`](../examples/mixed-application/README.md) is the maintained executable example. It demonstrates:

- multiple `src/` and `legacy/` roots under one Composer PSR-4 prefix;
- ++PHP implementing an ordinary-PHP `Repository<T>` interface;
- ordinary PHP accepting a generated `Box<Person>`;
- ++PHP consuming PHP native types, PHPDoc list/map/generic metadata, and a stub-supplied checked error;
- PHP and ++PHP Composer `autoload.files` entries;
- union and intersection types, typed loops and arrays, erased generics, checked errors, and `when`;
- a web entrypoint and executable generated console entrypoint; and
- static Composer bootstrap relocation from source-relative to output-relative paths.

Configured stubs are analysis inputs, not runtime outputs. A compatible stub may enrich a project declaration with call, member, generic, array, or checked-error metadata without causing `P2034`; contradictory native or PHPDoc contracts fail with `P6012` and both locations. Duplicate project-owned class-like or function declarations still fail with `P2034`. Known PHP, stub, installed-package, and PHP-platform calls are compiler-validated. `P4005` is reserved for genuinely dynamic invocation, while an external declaration outside known context remains explicitly deferred rather than fabricated as missing or dynamic.

Installed Composer package metadata supplies production PSR-4, classmap, and files declarations in Composer order. Classmap and files sources are indexed deterministically; referenced PSR-4 declarations load lazily and follow supported native/PHPDoc type references. The compiler parses these sources as data with explicit package provenance and never executes `vendor/autoload.php`, autoload files, top-level dependency code, Composer scripts, plugins, or application bootstraps. Unreadable, invalid, or excessive declaration surfaces fail closed with P6013–P6015.

The verified target PHP signature package contributes core and extension functions, constants, classes, methods, and properties. Reviewed intrinsics refine flow-sensitive behavior without replacing the broad platform declaration. Precedence is configured stub, project, Composer dependency, PHP platform, then intrinsic refinement; project/platform collisions report `P6017`. See [Portable Declaration Context](portable-declarations.md).

## Repository Workflow

Install dependencies at the repository root, then use the compiler checkout against the example:

~~~bash
composer install --no-interaction --no-progress
cd examples/mixed-application
composer install --no-interaction --no-progress --no-scripts
php ../../bin/ppphp composer:configure --dry-run
php ../../bin/ppphp composer:configure
php ../../bin/ppphp check
php ../../bin/ppphp build
composer update --lock --no-interaction --no-progress --no-scripts
composer dump-autoload --optimize --no-interaction
php public/index.php
php build/ppphp/console.php
~~~

Run the complete automated certification from the repository root:

~~~bash
composer verify:mixed-application
~~~

The verifier starts from a clean temporary copy. It proves that Composer's dry run does not write, projection is idempotent, a complete build produces the expected compile/copy manifest and maps, generated PHP is strict and lint-clean, copied PHP retains its hash, and both entrypoints run with normal and authoritative classmaps. It then creates a source-free deployment and runs the entrypoints again.

## Runtime And Deployment

`ppphp composer:configure` preserves the source-oriented root mappings beneath `extra.ppphp` for compiler analysis and projects their runtime forms into the configured output. When several source roots in one mapping resolve to the same output root, the projected list is deduplicated while the distinct source metadata is retained.

Run a complete pathless `ppphp build` before regenerating optimized or authoritative Composer metadata. Deploy the root `composer.json`, `composer.lock`, installed `vendor/`, public PHP entrypoints, and the configured output tree. Project source, configured stubs, `.ppphp-cache`, and compiler source are not runtime requirements.

Focused checks and builds remain developer operations. A focused command reports only selected-source failures, uses valid unselected PHP/++PHP declarations as context, and ignores unrelated invalid unselected sources. A production deployment should always come from a complete pathless build.

## Current Boundary

There is no entrypoint graph, transitive tree-shaking, watch mode, deployment bundler, or automatic Composer execution. The compiler does not load project autoload files, Composer scripts or plugins, application bootstraps, or user analysis configuration during analysis. Cross-language findings use the same catalog, deterministic processing, original-source rendering, and JSON schema documented in [Diagnostics](diagnostics.md). Deep ordinary-PHP bodies and generator-specific return flow remain Optional supplemental capabilities. Dynamically generated loaders, undeclared autoload behavior, and deep dependency bodies are outside the portable declaration contract.
