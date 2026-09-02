# Portable Declaration Context

> **Status:** Implemented in Stage 13C, completed by the post-Stage-13C gate, and transactionally hardened in Stage 13D for PHP 8.4 built-ins, installed Composer dependencies, and source-free dependency indexes.

The compiler builds callable and member contracts from source data without loading application or dependency code. Configured stubs have highest declaration-context precedence, followed by project declarations, installed Composer dependencies, the target PHP signature package, and reviewed intrinsic refinements. Conflicting project declarations against the PHP platform report `P6017`; a lower-precedence source never silently replaces an authoritative higher-precedence contract.

## PHP Signature Package

`resources/php-signatures/8.4/` is generated from the official `php/php-src` tag `php-8.4.23` at commit `52cee85adfeeb6f017f2ac796ab7973353702c20`. The manifest records that provenance, the generator format, target PHP version, module hashes, declaration counts, conditions, aliases, and reviewed overrides. The checked-in package contains normalized declaration shards rather than executable stubs or runtime reflection output.

Generate a candidate package from an exact upstream checkout:

~~~bash
php tools/generate-php-signatures.php \
  --php-src=/path/to/php-src-at-php-8.4.23 \
  --target=8.4 \
  --expected-ref=php-8.4.23 \
  --expected-commit=52cee85adfeeb6f017f2ac796ab7973353702c20 \
  --output=/path/to/candidate
~~~

Verify the committed package and its hashes:

~~~bash
composer verify:php-signatures
~~~

Generation is deterministic: two clean runs from the same upstream commit produce byte-identical output. The importer normalizes supported stub syntax, preprocessor and availability conditions, aliases, constants, functions, classes, methods, and properties into compiler-owned data. Exhaustive conditional function variants are reduced to a conservative common contract; incompatible or ambiguous alternatives fail generation instead of being guessed. A small reviewed override set records only behavior that the general signature model cannot express, such as collection-shape or narrowing refinements; the same intrinsic name list is shared by generation and semantic analysis so the layers cannot drift.

The loader verifies the manifest and shard hashes before parsing a module, loads modules lazily from referenced declarations, and caches immutable parsed modules within the process. Corrupt, incompatible, or unavailable package data reports `P6016` and fails closed. Runtime reflection is not a source of truth, so browser and native compiler-core analysis use the same target-version declarations even when the host runtime has different extensions.

## Composer Dependency Declarations

The compiler reads root Composer configuration and `vendor/composer/installed.json` as data. Each installed package retains its Composer name, install path, version/reference metadata, and declared production autoload order. Dependency `autoload-dev` is intentionally ignored.

Portable dependency loading supports:

- PSR-4 mappings, using the longest matching prefix and then Composer declaration order;
- PSR-0 namespace and PEAR underscore mappings after PSR-4 lookup;
- ordered classmap files/directories, Composer wildcards, and `exclude-from-classmap` patterns;
- ordered `autoload.files` entries as declaration sources;
- package-contained, cycle-safe static include traversal to depth 32 for the exact `__DIR__ . '<literal>'` form;
- exact negative existence-guard fallbacks and statically known class aliases;
- native and supported PHPDoc parameter, return, property, generic, inheritance, trait-use, and checked-error contracts; and
- transitive declaration references, so normal native analysis loads referenced PSR files lazily while classmap and files declarations remain discoverable.

Sources are parsed with explicit `ComposerDependency` provenance and diagnostic paths such as `<Composer vendor/package>/src/Clock.php`. They are never included, required, autoloaded, or otherwise executed. Static inspection resolves `class_alias()` and existence-guard intrinsics only when PHP namespace and `use function` rules prove the call reaches the global intrinsic. A dependency file may contain top-level executable code without that code running during analysis.

Declaration loading is bounded to 2,048 files, 16 MiB, 8,192 discovery entries, and include depth 32. Package and source paths must remain beneath canonical trusted project/vendor and package roots after symlink resolution. P6013–P6015 preserve the limit/source family; P6018 distinguishes relevant unavailable context, P6019 rejects an index atomically, P6020 reports declaration ambiguity, and P6021 reports a relevant unsafe path. Missing, unavailable, and dynamic context are not conflated, and unavailable packages do not fail unrelated selected source.

The root project's configured `.php` and `.ppphp` source roots remain the project-owned analysis surface. Root Composer mappings are preserved for runtime projection and backend context, but they do not authorize arbitrary project files outside `ppphp.json` source ownership. Generated Composer classmap execution, Composer scripts/plugins, `vendor/autoload.php`, application bootstraps, arbitrary condition evaluation, dynamic includes/aliases, and deep dependency-body analysis are outside the portable declaration contract.

`DependencyDeclarationProvider` supplies one declaration representation to semantic analysis. Installed source is the native default. The portable provider reads body-free format-2 manifest/shards for explicit internal, browser, and test workflows and does not scan installed source again. The writer strips function, method, constructor, closure, arrow, and property-hook implementations; the independent reader rejects any executable body. A producer identity records the exact compiler build, while a separate declaration-compatibility identity permits readers with the same format contract. See [Portable Dependency Index](dependency-index.md).

## Packaging Boundary

All required MVP and Boundary capabilities are compiler Complete in catalog version 4, including the separately evidenced source-free dependency-index boundary. Browser protocol version 2 reports `fullParity: true` with no required gaps. Stage 13D's technical promotion gates pass, while ADR 0004 retains the pinned PHPStan supplemental phase for MVP `ppphp check` and `ppphp build`, optional deep ordinary-PHP body analysis, generator-specific flow, and the established full-path contract.

The dependency direction is tested: compiler-core source cannot import the PHPStan adapter, `AnalysisProject`, or Symfony Process. `phpstan/phpstan` remains a runtime requirement while native full analysis depends on it; `phpstan/phpdoc-parser` remains a direct compiler dependency for portable PHPDoc parsing; and Symfony Process also supports production PHP linting. Moving PHPStan behind an optional package or installation profile requires a separate product decision and tests for native defaults, missing-backend diagnostics, distribution contents, and upgrade behavior. Stages 13C–13D establish and evaluate that design boundary without weakening the current distribution.
