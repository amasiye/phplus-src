# Compiler Architecture

> **Status:** Stage 9 is complete, including expression-oriented `when`; Stage 10 release hardening is next.

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

Project retains configuration, the deterministic source set, Composer metadata, configured stubs, a dependency graph, and a shared source manager. Composer source PSR-4, classmap, files, custom vendor paths, and installed-package metadata are analysis context rather than project-owned build inputs. When runtime mappings have been projected, ComposerResolver reads their original forms from extra.ppphp source metadata.

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| check | Check all project sources | Check the recursive subtree | Check one .php or .ppphp file |
| build | Check all; compile .ppphp and copy .php | Check and build the subtree | Compile one .ppphp or copy one .php |
| dump:ast | Invalid | Invalid | Dump one source AST |

Configured .stub.php files remain global syntax and type context for focused commands and are never outputs. Project-owned ordinary .php files are copied byte-for-byte into corresponding build paths.

`editor:definition` and `editor:semantic-tokens` are separate bounded, versioned standard-input protocols. Definition queries overlay the current unsaved `.ppphp` document in memory, build project symbols without invoking the analysis backend or writing caches, and return declaration spans for a UTF-8 byte offset. Semantic-token queries parse only the current in-memory document, derive PHP reserved words from the tokenizer, and classify contextual PHP plus ++PHP syntax-tree nodes into standard editor roles. See [Editor Protocol](editor-protocol.md).

## Frontend

PpphpParser implements the two-layer frontend. PhpToken::tokenize supplies exact source tokens. The extension parser records typed locals, typed loop declarations, throws clauses, generic declarations and references, typed arrays, and hierarchical `when` syntax as source-located nodes. A validated, length-preserving normalization plan masks extension-only syntax, then PhpParserAdapter parses the normalized source with the Composer-locked PHP-Parser API and PHP 8.4 grammar.

ParsedFile retains the original source, token stream, extension syntax index, normalization edits, normalized source, bidirectional source map, PHP AST, and parser tokens. Extension identities derive from node kind and original half-open byte span.

Normalization is parser-only. It preserves byte offsets and newline bytes so PHP parser diagnostics map back to the original source. Malformed extension syntax reports P1008 or P1009. The whole-file plan keeps one non-overlapping outer `when` placeholder while retaining descendant syntax in the extension index. `WhenFragmentParser` applies those descendant edits and parses each condition and branch body with the PHP 8.4 parser, preserving original positions and nested `when` identities.

## Semantic Analysis

SemanticAnalyzer collects project declarations, resolves names without mutating frontend nodes, and creates a SemanticModel for each selected .ppphp file. Pass order covers declaration collection, name resolution, binding checks, and strict ++PHP checks. Typed declarations are associated with normalized PHP assignments by exact variable and initializer offsets.

Each source file owns one executable file scope shared across namespace statement lists. A function, method, closure, arrow function, or native PHP property hook owns a separate callable scope. Ordinary nested blocks share their enclosing scope. Parameters, catch variables, $this, property-hook $value, and PHP superglobals are existing bindings. Typed declarations create LocalBinding records containing the fixed type, mutability, source spans, resolved initializer type, reads, and writes.

The semantic type model represents atomic, union, intersection, DNF, generic, type-parameter, typed-array, and unknown types. It owns canonical rendering, nullability, assignability, generic substitution, native erasure, and PHPDoc rendering. Composite forms are validated before assignment checks.

Generic declarations are indexed across classes, interfaces, traits, functions, and methods. The generic pass checks scope, duplicate parameters, arity, raw applications, bounds, inheritance, trait use, static restrictions, runtime-dependent uses, PHPDoc conflicts, and invariant applications. Ordinary PHP and stubs contribute generic templates through parsed PHPDoc.

The binding pass resolves definitive local expression types, including literal list and map shapes, nested typed arrays, exact new expressions, casts, known local reads, and simple unary or arithmetic expressions. It validates typed-array shape, keys, values, offset mutation, and readonly nesting. Unknown calls remain unknown rather than producing speculative local mismatches.

`CheckWhenExpressionsPass` resolves each placeholder by AST identity and original span, classifies its exact expression site, parses branch fragments, creates child scopes, validates control flow, infers the canonical result union, and checks its receiving context. The resulting typed `WhenExpressionAnalysis` models live in `SemanticModel`; other passes query them instead of interpreting the normalized `null` placeholder. Conditions and branches are also traversed by declaration, binding, type, generic, and checked-error infrastructure.

Project symbol tables record classes, interfaces, traits, enums, functions, methods, properties, promoted properties, parameters, parents, interfaces, trait uses, namespaces, source files, declaration spans, and precise name-selection spans. Resolved names honor namespace and import context while preserving original AST identity. The editor definition resolver follows typed receivers, parameters, local bindings, return/property types, applied generic substitutions, imports, traits, and inheritance against this same table.

Callable error contracts are prepared project-wide before body analysis. Native throws clauses are authoritative for .ppphp declarations; ordinary PHP and configured stubs contribute @throws metadata, with stubs taking precedence over project PHP declarations. The error-effect pass combines direct throws and resolved call contracts, removes matching catches, checks inherited contracts, and rejects checked errors that escape undeclared.

Typed for and foreach declarations enter the same enclosing PHP-compatible binding scope as ordinary typed locals. Foreach declarations extract exact list or map key/value contracts; broad arrays supply mixed. Collection relationships remain invariant.

Strict checking requires native parameter, property, and return types in .ppphp, with constructor and destructor return exemptions. It also rejects eval, variable variables, dynamic include targets, return-by-reference declarations, and dynamic property creation. Ordinary PHP is exempt from these ++PHP-only rules.

## Analysis Backend

ProjectChecker prepares `.ppphp-cache/analysis/` only after selected syntax and internal semantics succeed. Selected `.ppphp` files are lowered with complete generic, typed-array, checked-error, and `when` control-flow metadata; selected `.php` files are copied; valid unselected sources become scan context; configured stubs remain stub context; and Composer source paths are scanned as data. Deterministic source-root hashes isolate duplicate relative paths.

PhpStanProjectAnalyzer invokes the compiler-installed backend through PHP_BINARY and Symfony Process. A generated configuration supplies selected paths, context, stubs, target PHP version, and a workspace-local cache. User PHPStan configuration, autoload entrypoints, Composer scripts, and application bootstrap files are not executed.

Backend identifiers map to stable P2xxx diagnostics and original source spans. Internal and backend findings are deduplicated by category and source location. Infrastructure failures use P6005–P6007.

Every selected source is parsed and every selected .ppphp model is analyzed before a build writes output. The compiler-owned backend configuration enables checked-exception reporting and maps supported exception findings to P4xxx diagnostics.

## Lowering And Writing

PhpLowerer lowers typed local and loop declarations, erases generic syntax and throws clauses, then lowers `when` expressions against the original source. It returns GeneratedPhp containing the output, applied edits, and generated-to-original source map. Typed declaration passes replace only the declaration prefix:

~~~php
readonly string $name = 'Andrew';
~~~

becomes:

~~~php
/** @var string $name */ $name = 'Andrew';
~~~

The initializer, variable, comments, newline style, Unicode, and unaffected bytes remain intact. Edits use typed declaration spans, are validated for overlap, and are applied in reverse source order. Files without activated syntax remain byte-identical.

EraseGenericTypesPass removes declarations and applications from executable PHP and supplies canonical @template, @param, @return, @var, @extends, @implements, and @use metadata. EraseThrowsClausesPass removes native throws clauses. PhpDocEmitter coordinates one owning-docblock edit so existing descriptions, attributes, unrelated tags, newline style, and @throws metadata remain intact.

`LowerWhenExpressionsPass` consumes each outer containing statement and incorporates nested extension syntax without overlapping edits. It emits prerequisite evaluation statements, deterministic collision-free result variables, ordinary `if`/`elseif`/`else` inside compiler-owned `do` boundaries, literal break depths, and cleanup where control continues. Earlier call arguments and array members are hoisted only when required to preserve their textual evaluation point. No synthetic callable, runtime helper, or compiler-control exception is introduced. Source-edit submappings associate generated conditions, branch results, and temporary uses with their original `.ppphp` spans.

GeneratedPhpWriter accepts configuration, generated or copied contents, and an output path. It validates compiler ownership and symlink boundaries, writes a temporary file, and renames it into place. Output plans label each entry as ++PHP compilation or PHP copying. Collisions are checked across every project-owned .ppphp and .php source, including focused selected sources colliding with unselected sources. Whole-project replacement is not yet transactional, but semantic failure occurs before the first write.

Production ++PHP lowering also relocates statically analyzable Composer bootstrap expressions using the resolved Composer `vendor-dir` and the concrete output path. This keeps emitted entry scripts executable without source knowledge of the configured output directory. Other relative includes are preserved, and ordinary PHP copies remain byte-for-byte identical.

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

Stages 5 through 9 implement typed local and loop declarations, fixed and composite types, readonly enforcement, strict .ppphp declarations, unsafe-construct restrictions, project symbols, cross-file analysis, checked errors, Composer runtime projection, erased generics, typed arrays, expression-oriented `when`, and source-mapped PHPStan diagnostics. Stage 10 adds release hardening and manifests.

There is no entry-point model, dependency-driven tree-shaking, incremental build, production manifest, or atomic whole-project replacement.
