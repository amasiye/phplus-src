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
- typed declarations in for and foreach loop headers;
- fixed local types with conservative literal, expression, and assignment checks;
- file- and callable-scope declaration-before-use and readonly mutation checks;
- required native parameter, property, and return types in .ppp, with explicit mixed supported;
- project-wide argument, return, member, property, symbol, and nullability analysis;
- checked error declarations, propagation, catch handling, and override compatibility;
- rejection of eval, variable variables, dynamic include paths, return-by-reference declarations, and dynamic properties in .ppp;
- complete mixed build trees that compile .ppp and copy project-owned .php byte-for-byte;
- deterministic lowering of typed declarations and throws clauses to ordinary PHP with PHPDoc metadata;
- token-aware parsing of generics, typed arrays, and when, which remain inactive;
- Composer, ordinary PHPDoc, and configured stub analysis context;
- isolated PHPStan analysis beneath .ppphp-cache with diagnostics mapped to original source;
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

Checked errors are declared on named callables and must be caught or propagated:

~~~php
function loadUser(string $id): User throws UserNotFound
{
    throw new UserNotFound($id);
}
~~~

The generated PHP retains the contract as PHPDoc:

~~~php
/** @throws \UserNotFound */
function loadUser(string $id): User
{
    throw new UserNotFound($id);
}
~~~

Generics and typed arrays report P3001, and when reports P5001, until their implementation stages.

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

With no path, check validates every project-owned .php and .ppp file. A file or directory limits reported diagnostics to that selection while valid unselected sources, Composer metadata, and configured stubs provide type context. Unrelated invalid unselected files do not block a focused command.

With no path, build validates the complete selected project, compiles every selected .ppp file, and copies every selected project-owned .php file. A directory limits validation and output to its subtree. An explicit .ppp file compiles only that file, while an explicit .php file copies it byte-for-byte. Source files are never rewritten.

A build completes the same strict analysis as check before it writes any output. Generated files preserve source-root-relative paths. Files without activated syntax remain byte-identical; typed declarations are lowered without reformatting the rest of the file.

.ppp callables require native parameter and return types, except that constructors and destructors do not require return declarations. .ppp properties require native types. Explicit broad types such as mixed, array, object, callable, and iterable are valid. Ordinary .php files are exempt from these ++PHP declaration rules but still participate in genuine PHP type analysis.

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

See the [typed-local guide](docs/typed-local-bindings.md), [typed-loop guide](docs/typed-loop-bindings.md), [checked-error guide](docs/checked-errors.md), [language overview](docs/language.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/ppphp-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
