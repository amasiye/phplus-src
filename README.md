<p align="center">
    <img src="/resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" />
</p>

# ++PHP

++PHP (pronounced “plus plus PHP”) is a PHP source compiler and language superset. It adds compile-time language features while producing ordinary PHP for the official PHP runtime.

## Status

The compiler currently provides:

- project-wide discovery of mixed `.php` and `.ppp` source sets;
- complete-project, directory, and focused-file syntax checking;
- PHP 8.4 parsing with retained AST, comments, tokens, and source positions;
- token-aware parsing of typed locals, generics, typed arrays, `throws`, and `when`;
- exact extension nodes, length-preserving normalization, and bidirectional source mappings;
- byte-preserving `.ppp` to `.php` builds under the configured output path;
- Composer PSR-4, classmap, files, and installed-package metadata discovery;
- configured `.stub.php` discovery and syntax validation;
- deterministic AST output and structured console or JSON diagnostics;
- safe cleanup of compiler-owned output and cache directories.

Extension syntax is recognized but intentionally build-blocking until its semantic stage is implemented. Ordinary PHP-only `.ppp` files continue to check and build byte-for-byte as PHP. Typed-local semantics begin in Stage 5, checked-error semantics in Stage 7, generic and typed-array semantics in Stage 8, and `when` semantics and lowering in Stage 9.

## Requirements

- PHP `^8.4`
- Composer 2

## Installation

From a repository checkout:

```bash
composer install
php bin/ppphp --help
```

The Composer binary works from project-local and global Composer installations:

```bash
vendor/bin/ppphp --help
ppphp --help # after a Composer global installation
```

## Commands

```bash
ppphp init
ppphp check [file-or-directory]
ppphp build [file-or-directory]
ppphp clean
ppphp dump:ast <file.php|file.ppp>
```

With no path, `check` validates every project-owned `.php` and `.ppp` file. A file or directory limits checking to that selection.

With no path, `build` validates the complete project and emits every project-owned `.ppp` file. A directory limits both validation and emission to its subtree. An explicit `.ppp` file builds only that file. Ordinary `.php` files participate in validation but are never emitted or rewritten.

Source roots define ownership and output paths; there is no special entry point. Before a build writes output, every selected source and every configured stub is parsed. Generated files preserve their source-root-relative path and original bytes.

`init` creates `ppphp.json` and the configured output, cache, and stub directories. Generated configurations intentionally omit `$schema` while the schema URL is not yet versioned. Existing optional `$schema` strings remain valid and are never fetched by the compiler. The bundled [configuration schema](resources/schema/ppphp.schema.json) supports repository tooling and will be published at a stable versioned URL with releases.

`dump:ast` shows extension nodes, the normalized PHP AST, and normalization ranges. Recognized but inactive syntax is still dumped and returns a diagnostics exit status.

`clean` removes only validated output and cache paths. Use `--dry-run` to inspect those paths without deleting them.

Project commands accept `--working-directory`, `--config`, `--format=console|json`, and `--debug` where applicable. See [ppphp.json.dist](ppphp.json.dist) for the current configuration contract.

## Development

```bash
composer validate --strict
composer analyse
composer test
composer check
```

Further details are in the [language overview](docs/language.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/ppphp-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
