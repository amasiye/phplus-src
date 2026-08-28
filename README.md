<p align="center">
    <img src="/resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" />
</p>

# ++PHP

PHPlus is a PHP source compiler and language superset. It adds compile-time language features while producing ordinary PHP for the official PHP runtime.

## Status

The compiler currently provides:

- project-wide discovery of mixed `.php` and `.phplus` source sets;
- complete-project, directory, and focused-file syntax checking;
- PHP 8.4 parsing with retained AST, comments, tokens, and source positions;
- byte-preserving `.phplus` to `.php` builds under the configured output path;
- Composer PSR-4, classmap, files, and installed-package metadata discovery;
- configured `.stub.php` discovery and syntax validation;
- deterministic AST output and structured console or JSON diagnostics;
- safe cleanup of compiler-owned output and cache directories.

PHPlus-specific syntax, semantic analysis, and type checking are not implemented yet. At this stage, both `.php` and `.phplus` files must contain ordinary PHP 8.4 syntax.

## Requirements

- PHP `^8.4`
- Composer 2

## Installation

From a repository checkout:

```bash
composer install
php bin/phplus --help
```

The Composer binary works from project-local and global Composer installations:

```bash
vendor/bin/phplus --help
phplus --help # after a Composer global installation
```

## Commands

```bash
phplus init
phplus check [file-or-directory]
phplus build [file-or-directory]
phplus clean
phplus dump:ast <file.php|file.phplus>
```

With no path, `check` validates every project-owned `.php` and `.phplus` file. A file or directory limits checking to that selection.

With no path, `build` validates the complete project and emits every project-owned `.phplus` file. A directory limits both validation and emission to its subtree. An explicit `.phplus` file builds only that file. Ordinary `.php` files participate in validation but are never emitted or rewritten.

Source roots define ownership and output paths; there is no special entry point. Before a build writes output, every selected source and every configured stub is parsed. Generated files preserve their source-root-relative path and original bytes.

`init` creates `phplus.json` and the configured output, cache, and stub directories. Generated configurations intentionally omit `$schema` while the schema URL is not yet versioned. Existing optional `$schema` strings remain valid and are never fetched by the compiler. The bundled [configuration schema](resources/schema/phplus.schema.json) supports repository tooling and will be published at a stable versioned URL with releases.

`clean` removes only validated output and cache paths. Use `--dry-run` to inspect those paths without deleting them.

Project commands accept `--working-directory`, `--config`, `--format=console|json`, and `--debug` where applicable. See [phplus.json.dist](phplus.json.dist) for the current configuration contract.

## Development

```bash
composer validate --strict
composer analyse
composer test
composer check
```

Further details are in the [language overview](docs/language.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/phplus-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
