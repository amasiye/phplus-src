# PHP Signature Package

> **Target:** PHP 8.4
> **Package version:** 8.4.23.2
> **Status:** Complete in Stage 13C.

The checked-in PHP signature package supplies compiler-owned platform declarations independently of the host runtime and PHPStan. It is generated from the official `php/php-src` tag `php-8.4.23` at commit `52cee85adfeeb6f017f2ac796ab7973353702c20`; the tag, peeled commit, clean checkout, tracked stub inputs, and hashes are verified before generation.

`resources/php-signatures/8.4/manifest.json` identifies upstream provenance, package/generator format, target, tracked input hashes, directive audit, output hashes, counts, and licensing. Extension shards hold normalized declaration syntax, `symbols.json` provides the deterministic lazy-load index, `overrides.json` lists the small reviewed intrinsic refinement set, and `NOTICE.md` carries attribution under the PHP License 3.01. Ordinary CI verifies committed output and never regenerates it or requires network access:

```bash
composer verify:php-signatures
```

Generation is an explicit maintainer workflow through `tools/generate-php-signatures.php`. Normalization preserves functions, constants, class-like declarations, methods, properties, defaults, parameter names, references, variadics, aliases, tentative returns, and relevant availability conditions. Mutually exclusive conditional signatures are reduced only when every exhaustive branch supports one conservative contract; otherwise generation fails rather than selecting a build-specific signature.

Aliases resolve to the same compiler symbol contracts. Reviewed intrinsics refine language-critical narrowing and structured collection flow but do not replace broad platform declarations. Host reflection and PHPStan stubs are not sources of truth. An extension declaration describes the configured PHP target; deployment must still install extensions required by the application. Corrupt, incompatible, or wrong-target package data fails closed with `P6016`, and project collisions with platform declarations report `P6017`.

See [Portable Declaration Context](portable-declarations.md) for precedence and [Portable Dependency Index](dependency-index.md) for the separate Composer package format.
