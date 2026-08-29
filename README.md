<p align="center">
    <img src="/resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" />
</p>

# ++PHP

++PHP (pronounced “plus plus PHP”) is a PHP source compiler and language superset. It adds compile-time language features and emits ordinary PHP for the official PHP runtime.

## Status

The compiler currently provides:

- mixed .php and .ppp project discovery across one or more source roots;
- complete-project, directory, and focused-file checking and building;
- PHP 8.4 parsing with retained AST, comments, tokens, and source positions;
- active explicitly typed mutable locals and readonly local bindings;
- fixed local types with conservative literal, expression, and assignment checks;
- callable-scope declaration-before-use and readonly mutation checks;
- deterministic lowering of typed locals to PHPDoc plus ordinary PHP assignments;
- token-aware parsing of generics, typed arrays, throws, and when, which remain inactive;
- Composer autoload metadata and configured stub discovery;
- structured console and JSON diagnostics; and
- safe writes and cleanup beneath compiler-owned output and cache directories.

A valid typed local:

~~~php
function greeting(string $input): string
{
    string $name = trim($input);
    readonly string $prefix = 'Hello';

    return $prefix . ', ' . $name;
}
~~~

is emitted as ordinary PHP:

~~~php
function greeting(string $input): string
{
    /** @var string $name */ $name = trim($input);
    /** @var string $prefix */ $prefix = 'Hello';

    return $prefix . ', ' . $name;
}
~~~

Generics and typed arrays report P3001, throws reports P4001, and when reports P5001 until their implementation stages.

## Requirements

- PHP ^8.4
- Composer 2

## Installation

From a repository checkout:

~~~bash
composer install
php bin/ppphp --help
~~~

Composer exposes the executable for project-local and global installations:

~~~bash
vendor/bin/ppphp --help
ppphp --help
~~~

## Commands

~~~bash
ppphp init
ppphp check [file-or-directory]
ppphp build [file-or-directory]
ppphp clean
ppphp dump:ast <file.php|file.ppp>
~~~

With no path, check validates every project-owned .php and .ppp file. A file or directory limits checking to that selection.

With no path, build validates the complete selected project and emits every selected .ppp file. A directory limits validation and emission to its subtree. An explicit .ppp file builds only that file. Ordinary .php files participate in syntax context but are never emitted or rewritten.

A build completes parsing and semantic analysis before it writes any output. Generated files preserve source-root-relative paths. Files without activated syntax remain byte-identical; typed declarations are lowered without reformatting the rest of the file.

init creates ppphp.json and the configured output, cache, and stub directories. Generated configurations omit $schema until a versioned immutable schema URL is published. The bundled [configuration schema](resources/schema/ppphp.schema.json) remains available for repository tooling.

dump:ast shows extension nodes, normalized PHP AST data, and normalization ranges. clean removes only validated compiler-owned output and cache paths; --dry-run reports those paths without deleting them.

Project commands accept --working-directory, --config, --format=console|json, and --debug where applicable. See [ppphp.json.dist](ppphp.json.dist) for the configuration contract.

## Development

~~~bash
composer validate --strict
composer analyse
composer test
composer check
~~~

See the [typed-local guide](docs/typed-local-bindings.md), [language overview](docs/language.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/ppphp-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
