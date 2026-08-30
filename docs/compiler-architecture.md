# Compiler Architecture

> **Status:** Stage 7 is complete, including typed loop bindings and checked-error analysis.

++PHP is a staged source compiler that emits ordinary PHP:

~~~text
configuration and discovery
    -> PHP and ++PHP parsing
    -> ++PHP semantic validation
    -> isolated analysis workspace
    -> pinned PHPStan analysis
    -> original-source diagnostic mapping
    -> production lowering
    -> safe ordinary-PHP writes
~~~

## Project Loading And Selection

ProjectConfigLoader reads ppphp.json from an explicit project root and validates normalized paths, source ownership, exclusions, and compiler-owned output and cache boundaries.

FileDiscovery recursively indexes case-insensitive .php and .ppphp files beneath configured source roots. It applies exclusions before selection, avoids directory-symlink traversal, rejects escaping file symlinks, deduplicates physical files, and assigns overlapping roots to the most-specific owner deterministically.

Project retains configuration, the deterministic source set, Composer metadata, configured stubs, a dependency graph, and a shared source manager. Composer PSR-4, classmap, files, custom vendor paths, and installed-package metadata are analysis context rather than project-owned build inputs.

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| check | Check all project sources | Check the recursive subtree | Check one .php or .ppphp file |
| build | Check all; compile .ppphp and copy .php | Check and build the subtree | Compile one .ppphp or copy one .php |
| dump:ast | Invalid | Invalid | Dump one source AST |

Configured .stub.php files remain global syntax and type context for focused commands and are never outputs. Project-owned ordinary .php files are copied byte-for-byte into corresponding build paths.

## Frontend

PpphpParser implements the two-layer frontend. PhpToken::tokenize supplies exact source tokens. The extension parser records typed locals, typed loop declarations, throws clauses, and inactive syntax as source-located nodes. A validated, length-preserving normalization plan masks extension-only syntax, then PhpParserAdapter parses the normalized source with the Composer-locked PHP-Parser API and PHP 8.4 grammar.

ParsedFile retains the original source, token stream, extension syntax index, normalization edits, normalized source, bidirectional source map, PHP AST, and parser tokens. Extension identities derive from node kind and original half-open byte span.

Normalization is parser-only. It preserves byte offsets and newline bytes so PHP parser diagnostics map back to the original source. Malformed extension syntax reports P1008 or P1009. Inactive generic and when syntax reports its feature-family diagnostic instead of a raw PHP parser error.

## Semantic Analysis

SemanticAnalyzer collects project declarations, resolves names without mutating frontend nodes, and creates a SemanticModel for each selected .ppphp file. Pass order covers declaration collection, name resolution, binding checks, and strict ++PHP checks. Typed declarations are associated with normalized PHP assignments by exact variable and initializer offsets.

Each source file owns one executable file scope shared across namespace statement lists. A function, method, closure, arrow function, or native PHP property hook owns a separate callable scope. Ordinary nested blocks share their enclosing scope. Parameters, catch variables, $this, property-hook $value, and PHP superglobals are existing bindings. Typed declarations create LocalBinding records containing the fixed type, mutability, source spans, resolved initializer type, reads, and writes.

The binding pass resolves only definitive local expression types: literals, broad arrays, closures, exact new expressions, casts, known local reads, and simple unary or arithmetic expressions. Unknown calls remain unknown rather than producing speculative local mismatches.

Project symbol tables record classes, interfaces, traits, enums, functions, methods, properties, promoted properties, parameters, parents, interfaces, trait uses, namespaces, source files, and declaration spans. Resolved names honor namespace and import context while preserving original AST identity.

Callable error contracts are prepared project-wide before body analysis. Native throws clauses are authoritative for .ppphp declarations; ordinary PHP and configured stubs contribute @throws metadata, with stubs taking precedence over project PHP declarations. The error-effect pass combines direct throws and resolved call contracts, removes matching catches, checks inherited contracts, and rejects checked errors that escape undeclared.

Typed for and foreach declarations enter the same enclosing PHP-compatible binding scope as ordinary typed locals. Foreach element and key types use exact canonical matching until the generic type system is active.

Strict checking requires native parameter, property, and return types in .ppphp, with constructor and destructor return exemptions. It also rejects eval, variable variables, dynamic include targets, return-by-reference declarations, and dynamic property creation. Ordinary PHP is exempt from these ++PHP-only rules.

## Analysis Backend

ProjectChecker prepares `.ppphp-cache/analysis/` only after selected syntax and internal semantics succeed. Selected `.ppphp` files are lowered; selected `.php` files are copied; valid unselected sources become scan context; configured stubs remain stub context; and Composer paths are scanned as data. Deterministic source-root hashes isolate duplicate relative paths.

PhpStanProjectAnalyzer invokes the compiler-installed backend through PHP_BINARY and Symfony Process. A generated configuration supplies selected paths, context, stubs, target PHP version, and a workspace-local cache. User PHPStan configuration, autoload entrypoints, Composer scripts, and application bootstrap files are not executed.

Backend identifiers map to stable P2xxx diagnostics and original source spans. Internal and backend findings are deduplicated by category and source location. Infrastructure failures use P6005–P6007.

Every selected source is parsed and every selected .ppphp model is analyzed before a build writes output. The compiler-owned backend configuration enables checked-exception reporting and maps supported exception findings to P4xxx diagnostics.

## Lowering And Writing

PhpLowerer lowers typed local and loop declarations and erases throws clauses against the original source. It returns GeneratedPhp containing the output, applied edits, and generated-to-original source map. Typed declaration passes replace only the declaration prefix:

~~~php
readonly string $name = 'Andrew';
~~~

becomes:

~~~php
/** @var string $name */ $name = 'Andrew';
~~~

The initializer, variable, comments, newline style, Unicode, and unaffected bytes remain intact. Edits use typed declaration spans, are validated for overlap, and are applied in reverse source order. Files without activated syntax remain byte-identical.

EraseThrowsClausesPass removes the native clause and PhpDocEmitter preserves or creates the owning docblock with canonical @throws metadata. Existing descriptions and unrelated tags remain intact.

GeneratedPhpWriter accepts configuration, generated or copied contents, and an output path. It validates compiler ownership and symlink boundaries, writes a temporary file, and renames it into place. Output plans label each entry as ++PHP compilation or PHP copying. Collisions are checked across every project-owned .ppphp and .php source, including focused selected sources colliding with unselected sources. Whole-project replacement is not yet transactional, but semantic failure occurs before the first write.

## Source Model And Diagnostics

SourceFile retains immutable contents and line starts. Positions use zero-based byte offsets with one-based lines and Unicode-code-point columns. Spans are half-open, may be empty, may end at EOF, and cannot cross files.

Diagnostics contain a stable code, severity, Title Case summary, message, optional primary and related labels, help, and debug metadata. Console and JSON renderers always report original source locations.

~~~text
P0xxx  configuration and project errors
P1xxx  lexing and syntax
P2xxx  bindings and local types
P3xxx  generic types
P4xxx  checked errors
P5xxx  when expressions
P6xxx  PHP, Composer, and analysis-backend interoperability
P7xxx  emission and generated PHP
P9xxx  internal compiler errors
~~~

## Current Boundary

Stages 5 through 7 implement typed local and loop declarations, fixed local types, readonly enforcement, strict .ppphp declarations, unsafe-construct restrictions, project symbols, cross-file type analysis, checked errors, and source-mapped PHPStan diagnostics. Stage 8 adds generics and typed arrays; Stage 9 adds when typing and lowering; and Stage 10 adds release hardening and manifests.

There is no entry-point model, dependency-driven tree-shaking, incremental build, production manifest, or atomic whole-project replacement.
