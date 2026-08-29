# Compiler Architecture

> **Status:** Project discovery, the extension frontend, and typed-local semantics and lowering are implemented through Stage 5.

++PHP is a staged source compiler that emits ordinary PHP:

~~~text
configuration and discovery
    -> PHP and ++PHP parsing
    -> ++PHP semantic validation
    -> production lowering
    -> safe ordinary-PHP writes
~~~

## Project Loading And Selection

ProjectConfigLoader reads ppphp.json from an explicit project root and validates normalized paths, source ownership, exclusions, and compiler-owned output and cache boundaries.

FileDiscovery recursively indexes case-insensitive .php and .ppp files beneath configured source roots. It applies exclusions before selection, avoids directory-symlink traversal, rejects escaping file symlinks, deduplicates physical files, and assigns overlapping roots to the most-specific owner deterministically.

Project retains configuration, the deterministic source set, Composer metadata, configured stubs, a dependency graph, and a shared source manager. Composer PSR-4, classmap, files, custom vendor paths, and installed-package metadata are analysis context rather than project-owned build inputs.

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| check | Check all project sources | Check the recursive subtree | Check one .php or .ppp file |
| build | Check all; emit all .ppp files | Check and emit the subtree | Check and emit one .ppp file |
| dump:ast | Invalid | Invalid | Dump one source AST |

Configured .stub.php files remain global syntax context for focused commands. An ordinary .php file is never a build target.

## Frontend

PpphpParser implements the two-layer frontend. PhpToken::tokenize supplies exact source tokens. The extension parser records typed locals and inactive syntax as source-located nodes. A validated, length-preserving normalization plan masks extension-only syntax, then PhpParserAdapter parses the normalized source with the Composer-locked PHP-Parser API and PHP 8.4 grammar.

ParsedFile retains the original source, token stream, extension syntax index, normalization edits, normalized source, bidirectional source map, PHP AST, and parser tokens. Extension identities derive from node kind and original half-open byte span.

Normalization is parser-only. It preserves byte offsets and newline bytes so PHP parser diagnostics map back to the original source. Malformed extension syntax reports P1008 or P1009. Inactive generic, checked-error, and when syntax reports its feature-family diagnostic instead of a raw PHP parser error.

## Stage 5 Semantic Analysis

SemanticAnalyzer creates a SemanticModel for each selected .ppp file and executes CheckBindingsPass. Typed declarations are associated with normalized PHP assignments by exact variable and initializer offsets.

A function, method, closure, arrow function, or native PHP property hook owns a callable scope. Ordinary nested blocks share that scope. Parameters, catch variables, $this, property-hook $value, and PHP superglobals are existing bindings. Typed declarations create LocalBinding records containing the fixed type, mutability, source spans, resolved initializer type, reads, and writes.

Stage 5 resolves only definitive local expression types: literals, broad arrays, closures, exact new expressions, casts, known local reads, and simple unary or arithmetic expressions. Unknown calls remain unknown rather than producing speculative mismatches. Class hierarchy and complete name resolution remain outside this stage.

The analyzer indexes unambiguous function and method declarations in currently parsed source so readonly locals cannot be passed to known by-reference parameters. Dynamic or ambiguous calls are left for Stage 6.

Every selected source is parsed and every selected .ppp model is analyzed before a build writes output.

## Lowering And Writing

PhpLowerer executes LowerLocalDeclarationsPass against the original source. The pass replaces only the declaration prefix:

~~~php
readonly string $name = 'Andrew';
~~~

becomes:

~~~php
/** @var string $name */ $name = 'Andrew';
~~~

The initializer, variable, comments, newline style, Unicode, and unaffected bytes remain intact. Edits use TypedLocalDeclaration spans, are validated for overlap, and are applied in reverse source order. Files without activated syntax remain byte-identical.

GeneratedPhpWriter accepts configuration, generated contents, and an output path. It validates compiler ownership and symlink boundaries, writes a temporary file, and renames it into place. Output planning rejects collisions before emission. Whole-project replacement is not yet transactional, but semantic failure occurs before the first write.

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
P6xxx  PHP and Composer interoperability
P7xxx  emission and generated PHP
P9xxx  internal compiler errors
~~~

## Current Boundary

Stage 5 implements typed local declarations, fixed local types, readonly enforcement, and local-declaration lowering. Stage 6 adds strict whole-project types and the PHPStan adapter; Stage 7 checked errors; Stage 8 generics and typed arrays; Stage 9 when typing and lowering; and Stage 10 release hardening, manifests, and production source maps.

There is no entry-point model, dependency-driven tree-shaking, incremental build, production manifest, or atomic whole-project replacement.
