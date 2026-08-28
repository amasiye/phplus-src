# PHPlus

PHPlus is a PHP source compiler and language superset. It is designed to add compile-time language features while producing ordinary PHP for the official PHP runtime.

## Status

The current compiler accepts ordinary PHP syntax in one explicit `.phplus` file. It provides:

- project initialization and configuration validation;
- PHP 8.4 parsing with retained AST, comments, tokens, and source positions;
- syntax checking for a single source file;
- byte-preserving `.phplus` to `.php` builds under the configured output path;
- deterministic AST output;
- safe cleanup of compiler-owned output and cache directories;
- structured diagnostics in console and JSON formats.

PHPlus-specific syntax, project-wide discovery, semantic analysis, and type checking are not implemented yet.

## Requirements

- PHP `^8.4`
- Composer 2

## Installation

From a repository checkout:

```bash
composer install
php bin/phplus --help
```

The Composer binary supports project-local and global package installations:

```bash
vendor/bin/phplus --help
phplus --help # after a Composer global installation
```

## Commands

```bash
phplus init
phplus check <file.phplus>
phplus build <file.phplus>
phplus clean
phplus dump:ast <file.phplus>
```

`init` creates `phplus.json` and the configured output, cache, and stub directories. It refuses to replace an existing configuration unless `--force` is supplied.

`check` parses one explicit `.phplus` file as PHP 8.4. `build` performs the same check and copies the original bytes to the corresponding `.php` path under the configured output directory. `dump:ast` displays the parsed ordinary PHP AST and source-position metadata.

`clean` removes only the validated output and cache paths. Use `--dry-run` to inspect those paths without deleting them.

Project commands accept `--working-directory`, `--config`, `--format=console|json`, and `--debug` where applicable. See [phplus.json.dist](phplus.json.dist) and the [configuration schema](resources/schema/phplus.schema.json) for the current configuration contract.

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
