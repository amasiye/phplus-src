# PHPlus

PHPlus is a PHP source compiler and language superset. It is designed to add compile-time language features while producing ordinary PHP for the official PHP runtime.

## Status

The project foundation is implemented. It currently provides:

- project initialization and configuration validation;
- safe cleanup of compiler-owned output and cache directories;
- source files with byte offsets, Unicode-aware columns, and source spans; and
- structured diagnostics in console and JSON formats.

Source parsing, type checking, and PHP generation are not implemented. `check`, `build`, and `dump:ast` validate their inputs and then report that the compiler frontend is unavailable.

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
phplus check
phplus build
phplus clean
phplus dump:ast <file>
```

`init` creates `phplus.json` and the configured output, cache, and stub directories. It refuses to replace an existing configuration unless `--force` is supplied.

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
