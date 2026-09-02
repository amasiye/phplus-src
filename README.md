<p align="center">
    <img src="/resources/images/ppphp-emblem.svg" alt="++PHP Logo" width="200" />
</p>

# ++PHP

++PHP (pronounced “plus plus PHP”) is a PHP source compiler and language superset. It adds compile-time language features and emits ordinary PHP for the official PHP runtime.

## Status

Stages 0–12, the post-Stage-12 semantic closure, Stages 13A–13C, and the post-Stage-13C portable-dependency completion gate are complete. Stage 13D incremental performance, security, and hardening is next. Native `check` and `build` still use the pinned PHPStan supplemental backend.

The current compiler version is `dev-2026.3.1`. ++PHP uses
[quarterly CalVer with distinct release channels](docs/versioning.md): Stable is
the default acquisition channel, while Release Candidate and Development
releases require explicit selection.

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
- atomic mixed build trees that compile .ppphp, copy project-owned .php byte-for-byte, and preserve the previous build on failure;
- structured union, intersection, DNF, generic, and typed-array type checking;
- erased generic declarations and applications with PHPDoc interoperability;
- typed lists and maps, including shape, key, value, foreach, and readonly checks;
- deterministic lowering of typed declarations, generics, typed arrays, and throws clauses to ordinary PHP with PHPDoc metadata;
- expression-oriented `when` with branch scopes, checked result types, nested expressions, and closure-free lowering;
- explicit Composer runtime projection from source mappings to generated output;
- complete installed-Composer declaration semantics and deterministic source-free dependency indexes, plus ordinary PHPDoc and configured-stub context, without executing dependency code;
- a verified, target-version PHP built-in signature package with reviewed intrinsic refinements;
- compiler-owned duplicate declaration and cross-boundary contract diagnostics;
- isolated PHPStan analysis beneath .ppphp-cache with diagnostics mapped to original source;
- a compiler-owned in-process project-analysis result with explicit `compilerCore` completeness;
- a typed 36-capability analyzer catalog and deterministic required/supplemental/optional parity corpus;
- an internal process-free browser protocol for one-shot compiler-owned checking;
- catalog-owned, source-framed console and stable JSON diagnostics with deterministic processing;
- bounded compiler-owned definition and semantic-token protocols for consistent editor intelligence;
- deterministic build manifests and persisted source maps;
- mandatory strict types and pre-commit PHP lint validation for generated .ppphp output;
- a repository-certified mixed PHP/++PHP application and source-free deployment workflow; and
- safe transactional writes, locking, stale cleanup, and cleanup beneath compiler-owned output and cache directories.

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
declare(strict_types=1);

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

Generic and typed-array syntax is compile-time only and is erased into ordinary PHP with compatible PHPDoc. A `when` expression produces a value from a mandatory final `else` and may be used as a typed-local initializer, assignment value, return operand, direct call argument, or direct array value:

~~~php
string $label = when ($score >= 80) {
    return 'Excellent';
} else when ($score >= 50) {
    return 'Pass';
} else {
    return 'Fail';
};
~~~

Each reachable branch path must return a value or terminate. Branch returns produce the expression result rather than returning from the enclosing callable. The compiler preserves lazy, left-to-right evaluation with deterministic temporary variables and ordinary closure-free PHP control flow.

## Requirements

- PHP ^8.4
- Composer 2

## Installation

Once the package is publicly released, the default Stable Composer installation
is:

~~~bash
composer require --dev atatusoft-ltd/ppphp-src
~~~

Release Candidate and immutable Development installation commands are not
published until they have been validated against supported Composer and real
package metadata. Composer's rolling `dev-develop` branch identity is not the
same as an immutable ++PHP version such as `dev-2026.3.1`.

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
ppphp clean [--dry-run]
ppphp dump:ast <file.php|file.ppphp>
ppphp list
ppphp --version
~~~

With no path, check validates every project-owned .php and .ppphp file. A file or directory limits reported diagnostics to that selection while valid unselected sources, Composer metadata, and configured stubs provide type context. Unrelated invalid unselected files do not block a focused command.

With no path, build validates the complete project and atomically replaces the compiler-owned output tree. A directory updates its recursive source scope while preserving output outside that scope. An explicit .ppphp file compiles only that file, while an explicit .php file copies it byte-for-byte. Partial builds merge against a compatible previous manifest; source files are never rewritten.

A build completes the same strict analysis as check before it commits output. Every compiled `.ppphp` file contains `declare(strict_types=1)`; an explicit `strict_types=0` is rejected. Ordinary `.php` copies remain byte-identical, while `.ppphp` lowering preserves unaffected source bytes and newline style. Each committed artifact has a SHA-256-backed manifest entry and a persisted source map, and every new candidate PHP file must pass `php -l` before the candidate replaces the live output. In `.ppphp` entry scripts, the compiler resolves the project-oriented `__DIR__ . '/vendor/autoload.php'` bootstrap through Composer metadata and rebases it for the generated file, so source never hardcodes the configured output directory.

The configured output directory, including its `.ppphp/manifest.json` and `.ppphp/source-maps/` metadata, is generated and compiler-owned. Do not edit it manually or place hand-maintained files there: a pathless build replaces the entire tree. See [build output](docs/build-output.md), [source maps](docs/source-maps.md), and the [mixed-project interoperability workflow](docs/interoperability.md).

.ppphp callables require native parameter and return types, except that constructors and destructors do not require return declarations. .ppphp properties require native types. Explicit broad types such as mixed, array, object, callable, and iterable are valid. Ordinary .php files are exempt from these ++PHP declaration rules but still participate in genuine PHP type analysis.

init creates ppphp.json and the configured output, cache, and stub directories. Generated configurations omit $schema until a versioned immutable schema URL is published. The bundled [configuration schema](resources/schema/ppphp.schema.json) remains available for repository tooling.

composer:configure explicitly projects root application PSR-4, classmap, and files mappings to generated output while preserving their source forms under extra.ppphp for analysis. Preview with --dry-run, then run the displayed Composer metadata commands. The compiler never runs Composer or project PHP automatically.

dump:ast shows extension nodes, normalized PHP AST data, and normalization ranges. clean removes only validated compiler-owned output and cache paths; --dry-run reports those paths without deleting them.

Editor integrations use the internal `editor:definition` command to resolve project-wide symbols and `editor:semantic-tokens` to classify PHP and ++PHP symbol roles against the current unsaved document. The versioned JSON protocols are documented in the [editor protocol guide](docs/editor-protocol.md); they are not replacements for the human-facing `check` or `build` commands.

Project commands accept --working-directory, --config, --format=console|json, and --debug where applicable. They are non-interactive and split console diagnostics to standard error when the terminal exposes a separate channel. See the [CLI guide](docs/cli.md), [diagnostic guide and code catalog](docs/diagnostics.md), and [ppphp.json.dist](ppphp.json.dist) for the complete contracts.

## Development

~~~bash
composer validate --strict
composer verify:version
composer analyse
composer test
composer check
composer verify:mixed-application
composer verify:analyzer-parity
composer verify:php-signatures
~~~

See the [language overview](docs/language.md), [versioning guide](docs/versioning.md), [CLI guide](docs/cli.md), [diagnostic guide](docs/diagnostics.md), [analyzer capability catalog](docs/analyzer-capabilities.md), [analyzer-independence plan](docs/analyzer-independence.md), [portable declaration guide](docs/portable-declarations.md), [dependency-index format](docs/dependency-index.md), [PHP signature package](docs/php-signatures.md), [type-flow guide](docs/type-flow-analysis.md), [mixed-project interoperability guide](docs/interoperability.md), [build output guide](docs/build-output.md), [source-map guide](docs/source-maps.md), [`when` expression guide](docs/when-expressions.md), [composite-type guide](docs/composite-types.md), [generics guide](docs/generics.md), [typed-array guide](docs/typed-arrays.md), [Composer runtime guide](docs/composer-runtime.md), [checked-error guide](docs/checked-errors.md), [editor protocol](docs/editor-protocol.md), [compiler architecture](docs/compiler-architecture.md), and [MVP plan](docs/ppphp-mvp-end-to-end-plan.md).

## License

Licensed under the [Apache License 2.0](LICENSE.txt).
