# Portable Declaration Context

> **Status:** Implemented in Stage 13C for PHP 8.4 built-ins and installed Composer dependencies.

The compiler builds callable and member contracts from source data without loading application or dependency code. Configured stubs have highest declaration-context precedence, followed by project declarations, installed Composer dependencies, the target PHP signature package, and reviewed intrinsic refinements. Conflicting project declarations against the PHP platform report `P6017`; a lower-precedence source never silently replaces an authoritative higher-precedence contract.

## PHP Signature Package

`resources/php-signatures/8.4/` is generated from the official `php/php-src` tag `php-8.4.23` at commit `52cee85adfeeb6f017f2ac796ab7973353702c20`. The manifest records that provenance, the generator format, target PHP version, module hashes, declaration counts, conditions, aliases, and reviewed overrides. The checked-in package contains normalized declaration shards rather than executable stubs or runtime reflection output.

Generate a candidate package from an exact upstream checkout:

~~~bash
php tools/generate-php-signatures.php \
  --php-src=/path/to/php-src-at-php-8.4.23 \
  --output=/path/to/candidate
~~~

Verify the committed package and its hashes:

~~~bash
composer verify:php-signatures
~~~

Generation is deterministic: two clean runs from the same upstream commit produce byte-identical output. The importer normalizes supported stub syntax, preprocessor and availability conditions, aliases, constants, functions, classes, methods, and properties into compiler-owned data. Unsupported or ambiguous declarations fail generation instead of being guessed. A small reviewed override set records only behavior that the general signature model cannot express, such as collection-shape or narrowing refinements; the same intrinsic name list is shared by generation and semantic analysis so the layers cannot drift.

The loader verifies the manifest and shard hashes before parsing a module, loads modules lazily from referenced declarations, and caches immutable parsed modules within the process. Corrupt, incompatible, or unavailable package data reports `P6016` and fails closed. Runtime reflection is not a source of truth, so browser and native compiler-core analysis use the same target-version declarations even when the host runtime has different extensions.

## Composer Dependency Declarations

The compiler reads root Composer configuration and `vendor/composer/installed.json` as data. Each installed package retains its Composer name, install path, version/reference metadata, and declared production autoload order. Dependency `autoload-dev` is intentionally ignored.

Portable dependency loading supports:

- PSR-4 mappings, using the longest matching prefix and then Composer declaration order;
- classmap files and directories, expanded deterministically;
- `autoload.files` entries as declaration sources;
- native and supported PHPDoc parameter, return, property, generic, inheritance, trait-use, and checked-error contracts; and
- transitive declaration references, so only referenced PSR-4 files are parsed while classmap and files declarations remain discoverable.

Sources are parsed with explicit `ComposerDependency` provenance and diagnostic paths such as `<Composer vendor/package>/src/Clock.php`. They are never included, required, autoloaded, or otherwise executed. A dependency file may contain top-level executable code without that code running during analysis.

The declaration index is bounded to 2,048 files, 16 MiB, and 8,192 classmap-discovery entries per analysis. `P6013` reports a resource-limit breach, `P6014` reports an unreadable declared source, and `P6015` reports a source that cannot provide valid declarations. These errors fail closed. A referenced name beneath a known installed PSR-4 prefix is therefore either resolved from the declared mapping or diagnosed as missing; it is not silently classified as an unknown external.

The root project's configured `.php` and `.ppphp` source roots remain the project-owned analysis surface. Root Composer mappings are preserved for runtime projection and backend context, but they do not authorize arbitrary project files outside `ppphp.json` source ownership. `exclude-from-classmap`, generated Composer classmap execution, Composer scripts/plugins, `vendor/autoload.php`, application bootstraps, and deep dependency-body analysis are outside the portable declaration contract.

## Packaging Boundary

All required MVP and Boundary capabilities are compiler Complete in catalog version 3, and browser protocol version 2 reports `fullParity: true` with no required gaps. This does not change the native product default. `ppphp check` and `ppphp build` still run the pinned PHPStan supplemental phase for optional deep ordinary-PHP body analysis, generator-specific flow, and the established full-path contract.

The dependency direction is tested: compiler-core source cannot import the PHPStan adapter, `AnalysisProject`, or Symfony Process. `phpstan/phpstan` remains a runtime requirement while native full analysis depends on it; `phpstan/phpdoc-parser` remains a direct compiler dependency for portable PHPDoc parsing; and Symfony Process also supports production PHP linting. Moving PHPStan behind an optional package or installation profile requires a separate product decision and tests for native defaults, missing-backend diagnostics, distribution contents, and upgrade behavior. Stage 13C establishes that design boundary without weakening the current distribution.
