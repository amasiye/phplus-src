<p align="center">
    <img src="/resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" />
</p>

# ++PHP

++PHP (pronounced “plus plus PHP”) is a PHP source compiler and language superset. It adds compile-time language features and emits ordinary PHP for the official PHP runtime.

## Status

The compiler currently provides:

- mixed .php and .ppphp project discovery across one or more source roots;
- complete-project, directory, and focused-file checking and building;
- PHP 8.4 parsing with retained AST, comments, tokens, and source positions;
- active explicitly typed mutable locals and readonly local bindings;
- typed declarations in for and foreach loop headers;
- fixed local types with conservative literal, expression, and assignment checks;
- file- and callable-scope declaration-before-use and readonly mutation checks;
- required native parameter, property, and return types in .ppphp, with explicit mixed supported;
- project-wide argument, return, member, property, symbol, and nullability analysis;
- checked error declarations, propagation, catch handling, and override compatibility;
- rejection of eval, variable variables, dynamic include paths, return-by-reference declarations, and dynamic properties in .ppphp;
- complete mixed build trees that compile .ppphp and copy project-owned .php byte-for-byte;
- structured union, intersection, DNF, generic, and typed-array type checking;
- erased generic declarations and applications with PHPDoc interoperability;
- typed lists and maps, including shape, key, value, foreach, and readonly checks;
- deterministic lowering of typed declarations, generics, typed arrays, and throws clauses to ordinary PHP with PHPDoc metadata;
- token-aware parsing of when expressions, which remain inactive;
- explicit Composer runtime projection from source mappings to generated output;
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

Generic and typed-array syntax is compile-time only and is erased into ordinary PHP with compatible PHPDoc. When expressions remain inactive and report P5001.

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
ppphp composer:configure [--dry-run]
ppphp check [file-or-directory]
ppphp build [file-or-directory]
ppphp clean
ppphp dump:ast <file.php|file.ppphp>
~~~

With no path, check validates every project-owned .php and .ppphp file. A file or directory limits reported diagnostics to that selection while valid unselected sources, Composer metadata, and configured stubs provide type context. Unrelated invalid unselected files do not block a focused command.

With no path, build validates the complete selected project, compiles every selected .ppphp file, and copies every selected project-owned .php file. A directory limits validation and output to its subtree. An explicit .ppphp file compiles only that file, while an explicit .php file copies it byte-for-byte. Source files are never rewritten.

A build completes the same strict analysis as check before it writes any output. Generated files preserve source-root-relative paths. Files without activated syntax remain byte-identical; typed declarations are lowered without reformatting the rest of the file.

.ppphp callables require native parameter and return types, except that constructors and destructors do not require return declarations. .ppphp properties require native types. Explicit broad types such as mixed, array, object, callable, and iterable are valid. Ordinary .php files are exempt from these ++PHP declaration rules but still participate in genuine PHP type analysis.

init creates ppphp.json and the configured output, cache, and stub directories. Generated configurations omit $schema until a versioned immutable schema URL is published. The bundled [configuration schema](resources/schema/ppphp.schema.json) remains available for repository tooling.

composer:configure explicitly projects root application PSR-4, classmap, and files mappings to generated output while preserving their source forms under extra.ppphp for analysis. Preview with --dry-run, then run the displayed Composer metadata commands. The compiler never runs Composer or project PHP automatically.

dump:ast shows extension nodes, normalized PHP AST data, and normalization ranges. clean removes only validated compiler-owned output and cache paths; --dry-run reports those paths without deleting them.

Project commands accept --working-directory, --config, --format=console|json, and --debug where applicable. See [ppphp.json.dist](ppphp.json.dist) for the configuration contract.

## Development

~~~bash
composer validate --strict
composer analyse
composer test
composer check
~~~

See the [language overview](docs/language.md), [composite-type guide](docs/composite-types.md), [generics guide](docs/generics.md), [typed-array guide](docs/typed-arrays.md), [Composer runtime guide](docs/composer-runtime.md), [checked-error guide](docs/checked-errors.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/ppphp-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
