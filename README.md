# PHPlus

> PHPlus is a working name.

## Overview

PHPlus is a PHP source compiler and language superset. It adds compile-time language features and emits clean PHP for the official PHP runtime.

```text
PHPlus source
    -> compile-time validation and lowering
    -> ordinary PHP
    -> official PHP runtime
```

## What PHPlus Is Not

PHPlus is not a native-code compiler or a replacement PHP runtime. Generated programs remain standard PHP and do not require a PHPlus runtime.

## Current Status

PHPlus is in foundation development. The CLI currently provides `--help` and `--version`; compiler commands and language features are under active development.

## MVP Scope

The MVP will include:

- erased generics
- strict project-wide types
- `val` and `var` local bindings
- `when` expressions
- checked errors through `throws` declarations
- mixed `.php` and `.phplus` projects

## High-Level Architecture

PHPlus uses a token-aware frontend for added syntax and `nikic/php-parser` for standard PHP. Compiler-owned semantic passes validate PHPlus rules, while PHPStan serves as a replaceable PHP analysis backend. Production lowering removes PHPlus-only syntax and emits deterministic PHP with source-mapped diagnostics.

## Requirements

- PHP `^8.4`
- Composer 2

## Development Setup

```bash
git clone git@github.com:amasiye/phplus-src.git
cd phplus-src
git switch develop
composer install
```

## Available Commands

```bash
php bin/phplus --version
php bin/phplus --help
composer validate --strict
composer analyse
composer test
composer check
```

## Documentation

- [MVP roadmap](docs/phplus-mvp-end-to-end-plan.md)
- [Language overview](docs/language.md)
- [Compiler architecture](docs/compiler-architecture.md)
- [PHPStan integration](docs/phpstan-integration.md)
- [Mixed projects](docs/mixed-projects.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

PHPlus is licensed under the [Apache License 2.0](LICENSE.txt).
